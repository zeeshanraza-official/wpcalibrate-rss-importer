<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Import;

use WPCalibrate\RSSImporter\Feed\FeedSource;
use WPCalibrate\RSSImporter\Feed\FeedItem;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Maps feed items to WordPress post structures, sanitizes remote HTML, and inserts posts.
 */
final class PostCreator {

    private AttributionManager $attributionManager;

    public function __construct(AttributionManager $attributionManager) {
        $this->attributionManager = $attributionManager;
    }

    /**
     * Create WordPress post from FeedItem.
     *
     * @return int|\WP_Error Post ID on success, or WP_Error on failure.
     */
    public function createPost(FeedSource $source, FeedItem $item): int|\WP_Error {
        $rawTitle   = $item->getTitle();
        $rawContent = $item->getContent();
        $rawExcerpt = $item->getExcerpt();

        // 1. Sanitize feed title
        $title = sanitize_text_field($rawTitle);
        if ($title === '') {
            $title = __('(Untitled Feed Item)', 'wpcalibrate-rss-importer');
        }

        // 2. Append attribution if enabled
        $contentWithAttribution = $this->attributionManager->appendAttribution($rawContent, $source, $item);

        // 3. Sanitize content using wp_kses_post to neutralize scripts, handlers, unsafe embeds
        $sanitizedContent = wp_kses_post($contentWithAttribution);

        // 4. Sanitize excerpt
        $sanitizedExcerpt = sanitize_textarea_field(strip_tags($rawExcerpt));

        // 5. Build base post arguments
        $postData = [
            'post_title'   => $title,
            'post_content' => $sanitizedContent,
            'post_excerpt' => $sanitizedExcerpt,
            'post_status'  => $source->getDestinationStatus(),
            'post_type'    => $source->getDestinationPostType(),
            'post_author'  => $source->getAuthorId(),
        ];

        // 6. Handle publication date
        if ($source->getDateBehavior() === 'feed_date' && $item->getDate() !== null) {
            $postData['post_date_gmt'] = $item->getDate();
            $postData['post_date']     = get_date_from_gmt($item->getDate());
        }

        /**
         * Action fired before creating post.
         *
         * @param FeedSource $source Feed source.
         * @param FeedItem   $item   Feed item.
         */
        do_action('wpcri_before_post_insert', $source, $item);

        /**
         * Filter post data before insert.
         *
         * @param array<string, mixed> $postData Post data array for wp_insert_post.
         * @param FeedSource           $source   Feed source.
         * @param FeedItem             $item     Feed item.
         */
        $postData = (array) apply_filters('wpcri_post_data', $postData, $source, $item);

        // 7. Insert the post
        $postId = wp_insert_post($postData, true);
        if (is_wp_error($postId) || $postId === 0) {
            return is_wp_error($postId)
                ? $postId
                : new \WP_Error('wpcri_post_insert_failed', __('Failed to create post record in WordPress.', 'wpcalibrate-rss-importer'));
        }

        /**
         * Action fired after post is successfully inserted.
         *
         * @param int        $postId Inserted post ID.
         * @param FeedSource $source Feed source.
         * @param FeedItem   $item   Feed item.
         */
        do_action('wpcri_after_post_insert', (int) $postId, $source, $item);

        return (int) $postId;
    }
}
