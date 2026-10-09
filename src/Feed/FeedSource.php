<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Feed;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Value object representing a Feed Source entity.
 */
final class FeedSource {

    private int $id;
    private string $name;
    private string $url;
    private bool $enabled;
    private string $destinationPostType;
    private string $destinationStatus;
    private int $authorId;
    /** @var array<string, array<int|string>> */
    private array $taxonomies;
    private bool $mapCategories;
    private string $mappedTaxonomy;
    private bool $autoCreateTerms;
    /** @var array<string, mixed> */
    private array $filtering;
    /** @var array<string, mixed> */
    private array $imageConfig;
    /** @var array<string, mixed> */
    private array $attribution;
    private bool $useCanonical;
    private string $updateMode; // 'never' | 'on_change'
    private string $dateBehavior; // 'feed_date' | 'import_date'
    private string $schedule; // '15min' | '30min' | 'hourly' | 'twicedaily' | 'daily' | 'manual'
    private int $batchSize;
    private ?string $lastSuccessfulRun;
    private ?string $lastAttemptedRun;
    private int $totalImported;
    private int $totalSkipped;
    private int $totalFailed;
    private ?string $lastError;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = []) {
        $this->id                  = (int) ($data['id'] ?? 0);
        $this->name                = sanitize_text_field((string) ($data['name'] ?? ''));
        $this->url                 = esc_url_raw(trim((string) ($data['url'] ?? '')));
        $this->enabled             = !empty($data['enabled']);
        $this->destinationPostType = sanitize_key((string) ($data['destination_post_type'] ?? 'post'));
        $status = (string) ($data['destination_status'] ?? 'draft');
        $this->destinationStatus = in_array($status, ['draft', 'pending', 'publish'], true) ? $status : 'draft';

        $this->authorId            = max(1, (int) ($data['author_id'] ?? 1));
        $this->taxonomies          = is_array($data['taxonomies'] ?? null) ? $data['taxonomies'] : [];
        $this->mapCategories       = !empty($data['map_categories']);
        $this->mappedTaxonomy      = sanitize_key((string) ($data['mapped_taxonomy'] ?? 'category'));
        $this->autoCreateTerms     = !empty($data['auto_create_terms']);
        $this->filtering           = is_array($data['filtering'] ?? null) ? $data['filtering'] : self::defaultFiltering();
        $this->imageConfig         = is_array($data['image_config'] ?? null) ? $data['image_config'] : self::defaultImageConfig();
        $this->attribution         = is_array($data['attribution'] ?? null) ? $data['attribution'] : self::defaultAttribution();
        $this->useCanonical        = !empty($data['use_canonical']);

        $updateMode = (string) ($data['update_mode'] ?? 'never');
        $this->updateMode = in_array($updateMode, ['never', 'on_change'], true) ? $updateMode : 'never';

        $dateBehavior = (string) ($data['date_behavior'] ?? 'feed_date');
        $this->dateBehavior = in_array($dateBehavior, ['feed_date', 'import_date'], true) ? $dateBehavior : 'feed_date';

        $schedule = (string) ($data['schedule'] ?? 'hourly');
        $this->schedule = in_array($schedule, ['15min', '30min', 'hourly', 'twicedaily', 'daily', 'manual'], true) ? $schedule : 'hourly';
        $this->batchSize           = max(1, min(100, (int) ($data['batch_size'] ?? 10)));
        $this->lastSuccessfulRun   = !empty($data['last_successful_run']) ? (string) $data['last_successful_run'] : null;
        $this->lastAttemptedRun    = !empty($data['last_attempted_run']) ? (string) $data['last_attempted_run'] : null;
        $this->totalImported       = max(0, (int) ($data['total_imported'] ?? 0));
        $this->totalSkipped        = max(0, (int) ($data['total_skipped'] ?? 0));
        $this->totalFailed         = max(0, (int) ($data['total_failed'] ?? 0));
        $this->lastError           = !empty($data['last_error']) ? sanitize_text_field((string) $data['last_error']) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultFiltering(): array {
        return [
            'include_keywords'   => '',
            'exclude_keywords'   => '',
            'keyword_match_type' => 'any', // 'any' | 'all'
            'filter_target'      => 'title_content', // 'title' | 'content' | 'title_content'
            'category_filter'    => '',
            'max_age_days'       => 0, // 0 = no restriction
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultImageConfig(): array {
        return [
            'import_images'      => true,
            'set_featured_image' => true,
            'max_image_size_kb'  => 5120, // 5MB
            'allowed_mimes'      => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultAttribution(): array {
        return [
            'enabled' => false,
            'format'  => '<p class="wpcri-attribution">' . sprintf(
                /* translators: 1: Link opening tag, 2: Feed source title, 3: Link closing tag */
                __('Source: %1$s%2$s%3$s', 'wpcalibrate-rss-importer'),
                '<a href="{source_url}" target="_blank" rel="noopener noreferrer">',
                '{source_title}',
                '</a>'
            ) . '</p>',
        ];
    }

    public function getId(): int {
        return $this->id;
    }

    public function getName(): string {
        return $this->name;
    }

    public function getUrl(): string {
        return $this->url;
    }

    public function isEnabled(): bool {
        return $this->enabled;
    }

    public function getDestinationPostType(): string {
        return $this->destinationPostType;
    }

    public function getDestinationStatus(): string {
        return $this->destinationStatus;
    }

    public function getAuthorId(): int {
        return $this->authorId;
    }

    /**
     * @return array<string, array<int|string>>
     */
    public function getTaxonomies(): array {
        return $this->taxonomies;
    }

    public function isMapCategories(): bool {
        return $this->mapCategories;
    }

    public function getMappedTaxonomy(): string {
        return $this->mappedTaxonomy;
    }

    public function isAutoCreateTerms(): bool {
        return $this->autoCreateTerms;
    }

    /**
     * @return array<string, mixed>
     */
    public function getFiltering(): array {
        return $this->filtering;
    }

    /**
     * @return array<string, mixed>
     */
    public function getImageConfig(): array {
        return $this->imageConfig;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttribution(): array {
        return $this->attribution;
    }

    public function isUseCanonical(): bool {
        return $this->useCanonical;
    }

    public function getUpdateMode(): string {
        return $this->updateMode;
    }

    public function getDateBehavior(): string {
        return $this->dateBehavior;
    }

    public function getSchedule(): string {
        return $this->schedule;
    }

    public function getBatchSize(): int {
        return $this->batchSize;
    }

    public function getLastSuccessfulRun(): ?string {
        return $this->lastSuccessfulRun;
    }

    public function getLastAttemptedRun(): ?string {
        return $this->lastAttemptedRun;
    }

    public function getTotalImported(): int {
        return $this->totalImported;
    }

    public function getTotalSkipped(): int {
        return $this->totalSkipped;
    }

    public function getTotalFailed(): int {
        return $this->totalFailed;
    }

    public function getLastError(): ?string {
        return $this->lastError;
    }

    /**
     * Export as array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return [
            'id'                    => $this->id,
            'name'                  => $this->name,
            'url'                   => $this->url,
            'enabled'               => $this->enabled,
            'destination_post_type' => $this->destinationPostType,
            'destination_status'    => $this->destinationStatus,
            'author_id'             => $this->authorId,
            'taxonomies'            => $this->taxonomies,
            'map_categories'        => $this->mapCategories,
            'mapped_taxonomy'       => $this->mappedTaxonomy,
            'auto_create_terms'     => $this->autoCreateTerms,
            'filtering'             => $this->filtering,
            'image_config'          => $this->imageConfig,
            'attribution'           => $this->attribution,
            'use_canonical'         => $this->useCanonical,
            'update_mode'           => $this->updateMode,
            'date_behavior'         => $this->dateBehavior,
            'schedule'              => $this->schedule,
            'batch_size'            => $this->batchSize,
            'last_successful_run'   => $this->lastSuccessfulRun,
            'last_attempted_run'    => $this->lastAttemptedRun,
            'total_imported'        => $this->totalImported,
            'total_skipped'         => $this->totalSkipped,
            'total_failed'          => $this->totalFailed,
            'last_error'            => $this->lastError,
        ];
    }
}
