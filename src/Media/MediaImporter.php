<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Media;

use WPCalibrate\RSSImporter\Feed\FeedSource;
use WPCalibrate\RSSImporter\Feed\FeedItem;
use WPCalibrate\RSSImporter\Feed\SafeFeedFetcher;
use WPCalibrate\RSSImporter\Logging\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Downloads, validates, sideloads, and deduplicates remote feed images for Featured Image assignment.
 */
final class MediaImporter {

    private SafeFeedFetcher $fetcher;
    private Logger $logger;

    public function __construct(Logger $logger) {
        $this->logger  = $logger;
        $this->fetcher = new SafeFeedFetcher(15);
    }

    /**
     * Import candidate image as post featured image.
     *
     * @return int|null Attachment ID on success, null on skip or failure.
     */
    public function importFeaturedImage(int $postId, FeedSource $source, FeedItem $item): ?int {
        $imageConfig = $source->getImageConfig();

        if (empty($imageConfig['import_images'])) {
            return null;
        }

        $imageUrl = $item->getBestCandidateImageUrl();
        if (empty($imageUrl)) {
            return null;
        }

        /**
         * Filter candidate image URL before importing.
         *
         * @param string     $imageUrl Remote image URL.
         * @param FeedSource $source   Feed source.
         * @param FeedItem   $item     Feed item.
         */
        $imageUrl = (string) apply_filters('wpcri_candidate_image_url', $imageUrl, $source, $item);
        if (empty($imageUrl)) {
            return null;
        }

        // 1. Check for previously imported identical image (deduplication)
        $existingAttachmentId = $this->findExistingAttachment($imageUrl);
        if ($existingAttachmentId !== null) {
            if (!empty($imageConfig['set_featured_image'])) {
                set_post_thumbnail($postId, $existingAttachmentId);
            }
            return $existingAttachmentId;
        }

        // 2. Validate URL safety (SSRF mitigation)
        $host = (string) wp_parse_url($imageUrl, PHP_URL_HOST);
        if (!$this->fetcher->isHostSafe($host)) {
            $this->logger->warning(
                sprintf(
                    /* translators: %s: Image URL */
                    __('Skipped image download: unsafe host (%s)', 'wpcalibrate-rss-importer'),
                    esc_url($imageUrl)
                ),
                $source->getId()
            );
            return null;
        }

        // Require core media APIs
        if (!function_exists('download_url') || !function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        /**
         * Action fired before downloading remote media.
         *
         * @param string $imageUrl Remote image URL.
         * @param int    $postId   Destination post ID.
         */
        do_action('wpcri_before_media_import', $imageUrl, $postId);

        // 3. Download to temporary file
        $tempFile = download_url($imageUrl, 15);
        if (is_wp_error($tempFile)) {
            $this->logger->warning(
                sprintf(
                    /* translators: 1: Image URL, 2: Error message */
                    __('Failed to download image %1$s: %2$s', 'wpcalibrate-rss-importer'),
                    esc_url($imageUrl),
                    $tempFile->get_error_message()
                ),
                $source->getId()
            );
            return null;
        }

        try {
            // 4. Validate file size
            $maxBytes = ((int) ($imageConfig['max_image_size_kb'] ?? 5120)) * 1024;
            $fileSize = (int) filesize($tempFile);
            if ($fileSize <= 0 || $fileSize > $maxBytes) {
                $this->logger->warning(
                    sprintf(
                        /* translators: 1: File size in bytes, 2: Max allowed bytes */
                        __('Image file size (%1$d bytes) exceeds allowed limit of %2$d bytes.', 'wpcalibrate-rss-importer'),
                        $fileSize,
                        $maxBytes
                    ),
                    $source->getId()
                );
                return null;
            }

            // 5. Validate MIME type
            $mimeType = $this->detectMimeType($tempFile);
            $allowedMimes = (array) ($imageConfig['allowed_mimes'] ?? ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

            if (!$mimeType || !in_array($mimeType, $allowedMimes, true)) {
                $this->logger->warning(
                    sprintf(
                        /* translators: 1: Detected MIME type, 2: Image URL */
                        __('Image rejected: unsupported or dangerous MIME type "%1$s" for %2$s.', 'wpcalibrate-rss-importer'),
                        esc_html((string) $mimeType),
                        esc_url($imageUrl)
                    ),
                    $source->getId()
                );
                return null;
            }

            // 6. Generate clean safe filename
            $extension = $this->getExtensionForMime($mimeType);
            $pathInfo  = pathinfo((string) wp_parse_url($imageUrl, PHP_URL_PATH));
            $baseName  = sanitize_file_name($pathInfo['filename'] ?? 'feed-image');
            if (empty($baseName)) {
                $baseName = 'feed-image-' . wp_generate_password(6, false);
            }
            $filename = "{$baseName}.{$extension}";

            $fileArray = [
                'name'     => $filename,
                'tmp_name' => $tempFile,
            ];

            // 7. Sideload into Media Library
            $desc = sanitize_text_field($item->getTitle());
            $attachmentId = media_handle_sideload($fileArray, $postId, $desc);

            if (is_wp_error($attachmentId)) {
                $this->logger->warning(
                    sprintf(
                        /* translators: %s: Error message */
                        __('Media sideload error: %s', 'wpcalibrate-rss-importer'),
                        $attachmentId->get_error_message()
                    ),
                    $source->getId()
                );
                return null;
            }

            // 8. Store image metadata for deduplication
            update_post_meta($attachmentId, '_wpcri_source_image_url', $imageUrl);
            update_post_meta($attachmentId, '_wpcri_source_id', (string) $source->getId());
            update_post_meta($attachmentId, '_wpcri_imported_at', current_time('mysql'));

            // 9. Assign as Featured Image
            if (!empty($imageConfig['set_featured_image'])) {
                set_post_thumbnail($postId, $attachmentId);
            }

            /**
             * Action fired after media import succeeds.
             *
             * @param int    $attachmentId Attachment ID.
             * @param string $imageUrl     Remote image URL.
             * @param int    $postId       Destination post ID.
             */
            do_action('wpcri_after_media_import', $attachmentId, $imageUrl, $postId);

            return (int) $attachmentId;
        } finally {
            // Always clean up temp file if it still exists
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * Check if an attachment already exists in Media Library for this source image URL.
     */
    private function findExistingAttachment(string $imageUrl): ?int {
        $attachments = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_wpcri_source_image_url',
                    'value'   => $imageUrl,
                    'compare' => '=',
                ],
            ],
            'no_found_rows'  => true,
        ]);

        if (!empty($attachments) && isset($attachments[0])) {
            return (int) $attachments[0];
        }

        return null;
    }

    /**
     * Detect MIME type safely using finfo or getimagesize.
     */
    private function detectMimeType(string $filePath): ?string {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $filePath);
                finfo_close($finfo);
                if (is_string($mime) && !empty($mime)) {
                    return strtolower($mime);
                }
            }
        }

        $imageSize = @getimagesize($filePath);
        if ($imageSize && !empty($imageSize['mime'])) {
            return strtolower((string) $imageSize['mime']);
        }

        return null;
    }

    /**
     * Get safe extension for validated image MIME type.
     */
    private function getExtensionForMime(string $mime): string {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            default      => 'jpg',
        };
    }
}
