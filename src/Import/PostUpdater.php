<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Import;

use WPCalibrate\RSSImporter\Feed\FeedSource;
use WPCalibrate\RSSImporter\Feed\FeedItem;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Updates existing imported posts idempotently when source content changes.
 * Only touches importer-managed fields without overwriting user changes or unrelated metadata.
 */
final class PostUpdater {

    private AttributionManager $attributionManager;

    public function __construct(AttributionManager $attributionManager) {
        $this->attributionManager = $attributionManager;
    }

    /**
     * Update an existing imported post with updated feed item content.
     *
     * @return int|\WP_Error Updated post ID or WP_Error on failure.
     */
    public function updatePost(int $postId, FeedSource $source, FeedItem $item): int|\WP_Error {
        $existing = get_post($postId);
        if (!$existing instanceof \WP_Post) {
            return new \WP_Error('wpcri_post_not_found', __('Destination post not found for update.', 'wpcalibrate-rss-importer'));
        }

        $title = sanitize_text_field($item->getTitle());
        if ($title === '') {
            $title = $existing->post_title;
        }

        $contentWithAttribution = $this->attributionManager->appendAttribution($item->getContent(), $source, $item);
        $sanitizedContent       = wp_kses_post($contentWithAttribution);
        $sanitizedExcerpt       = sanitize_textarea_field(strip_tags($item->getExcerpt()));

        // Only update fields managed by importer
        $updateData = [
            'ID'           => $postId,
            'post_title'   => $title,
            'post_content' => $sanitizedContent,
            'post_excerpt' => $sanitizedExcerpt,
        ];

        /**
         * Filter update post data.
         *
         * @param array<string, mixed> $updateData Data array for wp_update_post.
         * @param int                  $postId     Post ID.
         * @param FeedSource           $source     Feed source.
         * @param FeedItem             $item       Feed item.
         */
        $updateData = (array) apply_filters('wpcri_update_post_data', $updateData, $postId, $source, $item);

        $result = wp_update_post($updateData, true);
        if (is_wp_error($result) || $result === 0) {
            return is_wp_error($result)
                ? $result
                : new \WP_Error('wpcri_post_update_failed', __('Failed to update post record.', 'wpcalibrate-rss-importer'));
        }

        /**
         * Action fired after post is successfully updated.
         *
         * @param int        $postId Post ID.
         * @param FeedSource $source Feed source.
         * @param FeedItem   $item   Feed item.
         */
        do_action('wpcri_after_post_update', $postId, $source, $item);

        return $postId;
    }
}
