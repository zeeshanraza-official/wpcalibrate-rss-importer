<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Import;

use WPCalibrate\RSSImporter\Feed\FeedSource;
use WPCalibrate\RSSImporter\Feed\FeedRepository;
use WPCalibrate\RSSImporter\Feed\SafeFeedFetcher;
use WPCalibrate\RSSImporter\Feed\FeedParser;
use WPCalibrate\RSSImporter\Feed\FeedItem;
use WPCalibrate\RSSImporter\Filtering\FilterEngine;
use WPCalibrate\RSSImporter\Media\MediaImporter;
use WPCalibrate\RSSImporter\Logging\Logger;
use WPCalibrate\RSSImporter\Settings\SettingsManager;
use WPCalibrate\RSSImporter\Scheduling\ScheduleManager;
use WPCalibrate\RSSImporter\Scheduling\LockManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main orchestration service for feed preview, testing, and batch importing.
 */
final class ImportManager {

    private FeedRepository $feedRepository;
    private SettingsManager $settingsManager;
    private Logger $logger;
    private ScheduleManager $scheduleManager;
    private SafeFeedFetcher $fetcher;
    private FeedParser $parser;
    private IdentityService $identityService;
    private FilterEngine $filterEngine;
    private PostCreator $postCreator;
    private PostUpdater $postUpdater;
    private TaxonomyManager $taxonomyManager;
    private AttributionManager $attributionManager;
    private MediaImporter $mediaImporter;

    public function __construct(
        FeedRepository $feedRepository,
        SettingsManager $settingsManager,
        Logger $logger,
        ScheduleManager $scheduleManager
    ) {
        $this->feedRepository     = $feedRepository;
        $this->settingsManager    = $settingsManager;
        $this->logger             = $logger;
        $this->scheduleManager    = $scheduleManager;
        $this->fetcher            = new SafeFeedFetcher((int) $settingsManager->get('http_timeout', 15));
        $this->parser             = new FeedParser();
        $this->identityService    = new IdentityService();
        $this->filterEngine       = new FilterEngine();
        $this->attributionManager = new AttributionManager();
        $this->attributionManager->init();
        $this->postCreator        = new PostCreator($this->attributionManager);
        $this->postUpdater        = new PostUpdater($this->attributionManager);
        $this->taxonomyManager    = new TaxonomyManager();
        $this->mediaImporter      = new MediaImporter($this->logger);
    }

    /**
     * Test a feed URL without saving or importing content.
     *
     * @return array{title: string, description: string, link: string, count: int, sample_items: array<FeedItem>}|\WP_Error
     */
    public function testFeed(string $url): array|\WP_Error {
        $xml = $this->fetcher->fetch($url);
        if (is_wp_error($xml)) {
            $this->logger->warning(
                sprintf(
                    /* translators: 1: Feed URL, 2: Error message */
                    __('Feed test failed for %1$s: %2$s', 'wpcalibrate-rss-importer'),
                    esc_url($url),
                    $xml->get_error_message()
                )
            );
            return $xml;
        }

        $parsed = $this->parser->parse($xml);
        if (is_wp_error($parsed)) {
            $this->logger->warning(
                sprintf(
                    /* translators: 1: Feed URL, 2: Error message */
                    __('Feed test XML parsing failed for %1$s: %2$s', 'wpcalibrate-rss-importer'),
                    esc_url($url),
                    $parsed->get_error_message()
                )
            );
            return $parsed;
        }

        $items = $parsed['items'];
        $count = count($items);
        $samples = array_slice($items, 0, 5);

        $this->logger->success(
            sprintf(
                /* translators: 1: Feed URL, 2: Total items found */
                __('Feed test succeeded for %1$s. Found %2$d items.', 'wpcalibrate-rss-importer'),
                esc_url($url),
                $count
            )
        );

        return [
            'title'        => $parsed['title'],
            'description'  => $parsed['description'],
            'link'         => $parsed['link'],
            'count'        => $count,
            'sample_items' => $samples,
        ];
    }

    /**
     * Generate preview rows for a configured feed source without creating posts or media.
     *
     * @return array<array{item: FeedItem, filter_allowed: bool, filter_reason: string, is_duplicate: bool, existing_id: ?int, has_image: bool}>|\WP_Error
     */
    public function previewFeed(FeedSource $source, int $limit = 5): array|\WP_Error {
        $xml = $this->fetcher->fetch($source->getUrl());
        if (is_wp_error($xml)) {
            return $xml;
        }

        $parsed = $this->parser->parse($xml);
        if (is_wp_error($parsed)) {
            return $parsed;
        }

        $items = array_slice($parsed['items'], 0, max(1, min(20, $limit)));
        $previewData = [];

        foreach ($items as $item) {
            $filterResult = $this->filterEngine->evaluate($source, $item);
            $existingPost = $this->identityService->findExistingPost($source, $item);
            $imageUrl     = $item->getBestCandidateImageUrl();

            $previewData[] = [
                'item'           => $item,
                'filter_allowed' => $filterResult->isAllowed(),
                'filter_reason'  => $filterResult->getReason(),
                'is_duplicate'   => $existingPost !== null,
                'existing_id'    => $existingPost,
                'has_image'      => !empty($imageUrl),
            ];
        }

        return $previewData;
    }

    /**
     * Execute import batch for a given feed source.
     *
     * @return array{success: bool, imported: int, updated: int, skipped: int, failed: int, message: string}
     */
    public function importBatch(FeedSource $source, bool $isCron = false): array {
        $sourceId = $source->getId();

        // 1. Concurrency control: Acquire execution lock
        $lockToken = LockManager::acquireLock($sourceId);
        if ($lockToken === null) {
            $msg = sprintf(
                /* translators: %d: Feed source ID */
                __('Import skipped: Feed #%d is currently locked by another active process.', 'wpcalibrate-rss-importer'),
                $sourceId
            );
            $this->logger->warning($msg, $sourceId);
            return [
                'success'  => false,
                'imported' => 0,
                'updated'  => 0,
                'skipped'  => 0,
                'failed'   => 0,
                'message'  => $msg,
            ];
        }

        $imported = 0;
        $updated  = 0;
        $skipped  = 0;
        $failed   = 0;

        $this->logger->info(
            sprintf(
                /* translators: 1: Mode (Cron or Manual), 2: Feed source name */
                __('Import started (%1$s) for source "%2$s".', 'wpcalibrate-rss-importer'),
                $isCron ? 'Cron' : 'Manual',
                $source->getName()
            ),
            $sourceId
        );

        try {
            // 2. Safe remote fetch
            $xml = $this->fetcher->fetch($source->getUrl());
            if (is_wp_error($xml)) {
                $errorMsg = $xml->get_error_message();
                $this->logger->error("HTTP Fetch failed: {$errorMsg}", $sourceId);
                $this->feedRepository->updateRunStats($sourceId, false, 0, 0, 1, $errorMsg);
                return [
                    'success'  => false,
                    'imported' => 0,
                    'updated'  => 0,
                    'skipped'  => 0,
                    'failed'   => 1,
                    'message'  => $errorMsg,
                ];
            }

            // 3. XML parsing
            $parsed = $this->parser->parse($xml);
            if (is_wp_error($parsed)) {
                $errorMsg = $parsed->get_error_message();
                $this->logger->error("Parsing failed: {$errorMsg}", $sourceId);
                $this->feedRepository->updateRunStats($sourceId, false, 0, 0, 1, $errorMsg);
                return [
                    'success'  => false,
                    'imported' => 0,
                    'updated'  => 0,
                    'skipped'  => 0,
                    'failed'   => 1,
                    'message'  => $errorMsg,
                ];
            }

            // 4. Batch items slice
            $items = array_slice($parsed['items'], 0, $source->getBatchSize());

            foreach ($items as $item) {
                // A. Duplicate Identity Check
                $existingPostId = $this->identityService->findExistingPost($source, $item);
                $fingerprint    = $this->identityService->generateFingerprint($source, $item);

                if ($existingPostId !== null) {
                    if ($source->getUpdateMode() === 'on_change' && $this->identityService->hasItemChanged($existingPostId, $item)) {
                        // Update existing post
                        $updateResult = $this->postUpdater->updatePost($existingPostId, $source, $item);
                        if (!is_wp_error($updateResult)) {
                            $this->taxonomyManager->assignTaxonomies($existingPostId, $source, $item);
                            $this->identityService->recordPostIdentity($existingPostId, $source, $item, $fingerprint);
                            $this->attributionManager->recordCanonicalMeta($existingPostId, $source, $item);
                            $updated++;
                            $this->logger->info(
                                sprintf(
                                    /* translators: 1: Post title, 2: Post ID */
                                    __('Updated existing post "%1$s" (ID: %2$d)', 'wpcalibrate-rss-importer'),
                                    esc_html($item->getTitle()),
                                    $existingPostId
                                ),
                                $sourceId
                            );
                        } else {
                            $failed++;
                            $this->logger->error(
                                sprintf(
                                    /* translators: 1: Post title, 2: Error message */
                                    __('Failed updating post "%1$s": %2$s', 'wpcalibrate-rss-importer'),
                                    esc_html($item->getTitle()),
                                    $updateResult->get_error_message()
                                ),
                                $sourceId
                            );
                        }
                    } else {
                        // Skipped duplicate
                        $skipped++;
                        $this->logger->info(
                            sprintf(
                                /* translators: 1: Post title, 2: Post ID */
                                __('Skipped duplicate item "%1$s" (matches existing ID: %2$d)', 'wpcalibrate-rss-importer'),
                                esc_html($item->getTitle()),
                                $existingPostId
                            ),
                            $sourceId
                        );
                    }
                    continue;
                }

                // B. Filtering evaluation
                $filterResult = $this->filterEngine->evaluate($source, $item);
                if (!$filterResult->isAllowed()) {
                    $skipped++;
                    $this->logger->info(
                        sprintf(
                            /* translators: 1: Item title, 2: Reason */
                            __('Filtered out "%1$s": %2$s', 'wpcalibrate-rss-importer'),
                            esc_html($item->getTitle()),
                            $filterResult->getReason()
                        ),
                        $sourceId
                    );
                    continue;
                }

                // C. Post Creation
                $createdPostId = $this->postCreator->createPost($source, $item);
                if (is_wp_error($createdPostId)) {
                    $failed++;
                    $this->logger->error(
                        sprintf(
                            /* translators: 1: Item title, 2: Error message */
                            __('Failed to create post for "%1$s": %2$s', 'wpcalibrate-rss-importer'),
                            esc_html($item->getTitle()),
                            $createdPostId->get_error_message()
                        ),
                        $sourceId
                    );
                    continue;
                }

                // D. Taxonomies
                $this->taxonomyManager->assignTaxonomies($createdPostId, $source, $item);

                // E. Media Sideloading (Featured Image)
                $this->mediaImporter->importFeaturedImage($createdPostId, $source, $item);

                // F. Record identity & canonical metadata
                $this->identityService->recordPostIdentity($createdPostId, $source, $item, $fingerprint);
                $this->attributionManager->recordCanonicalMeta($createdPostId, $source, $item);

                $imported++;
                $this->logger->success(
                    sprintf(
                        /* translators: 1: Item title, 2: Post ID */
                        __('Successfully imported "%1$s" (Post ID: %2$d)', 'wpcalibrate-rss-importer'),
                        esc_html($item->getTitle()),
                        $createdPostId
                    ),
                    $sourceId
                );
            }

            // 5. Update Feed Source Statistics
            $this->feedRepository->updateRunStats($sourceId, true, $imported, $skipped, $failed);

            $summaryMessage = sprintf(
                /* translators: 1: Total imported, 2: Total updated, 3: Total skipped, 4: Total failed */
                __('Batch completed: %1$d imported, %2$d updated, %3$d skipped, %4$d failed.', 'wpcalibrate-rss-importer'),
                $imported,
                $updated,
                $skipped,
                $failed
            );

            $this->logger->success($summaryMessage, $sourceId);

            /**
             * Action fired after batch import finishes.
             *
             * @param FeedSource $source   Feed source.
             * @param int        $imported Total imported.
             * @param int        $updated  Total updated.
             * @param int        $skipped  Total skipped.
             * @param int        $failed   Total failed.
             */
            do_action('wpcri_import_completed', $source, $imported, $updated, $skipped, $failed);

            return [
                'success'  => true,
                'imported' => $imported,
                'updated'  => $updated,
                'skipped'  => $skipped,
                'failed'   => $failed,
                'message'  => $summaryMessage,
            ];
        } catch (\Throwable $e) {
            $errorMsg = sanitize_text_field($e->getMessage());
            $this->logger->error("Unexpected error during import: {$errorMsg}", $sourceId);
            $this->feedRepository->updateRunStats($sourceId, false, $imported, $skipped, $failed + 1, $errorMsg);

            do_action('wpcri_import_failed', $source, $errorMsg);

            return [
                'success'  => false,
                'imported' => $imported,
                'updated'  => $updated,
                'skipped'  => $skipped,
                'failed'   => $failed + 1,
                'message'  => $errorMsg,
            ];
        } finally {
            // Always release lock
            LockManager::releaseLock($sourceId, $lockToken);
        }
    }
}
