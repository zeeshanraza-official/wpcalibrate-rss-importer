<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Compatibility;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Discovers and filters compatible post types for feed destination selection.
 */
final class PostTypeHelper {

    /**
     * Post types to exclude strictly.
     *
     * @var array<string>
     */
    private const EXCLUDED_POST_TYPES = [
        'attachment',
        'revision',
        'nav_menu_item',
        'custom_css',
        'customize_changeset',
        'oembed_cache',
        'user_request',
        'wp_block',
        'wp_template',
        'wp_template_part',
        'wp_global_styles',
        'wp_navigation',
        'wpcri_feed',
        // WooCommerce internal types
        'shop_order',
        'shop_order_refund',
        'shop_coupon',
        'shop_order_placehold',
        'product_variation',
    ];

    /**
     * Get list of post types the current user can create posts in and that are compatible with standard content.
     *
     * @return array<string, string> Array of slug => label
     */
    public static function getCompatiblePostTypes(): array {
        $postTypes = get_post_types(['public' => true], 'objects');
        $compatible = [];

        foreach ($postTypes as $slug => $object) {
            if (in_array($slug, self::EXCLUDED_POST_TYPES, true)) {
                continue;
            }

            // Check if current user can publish or edit this post type
            if (!empty($object->cap->edit_posts) && !current_user_can($object->cap->edit_posts)) {
                continue;
            }

            // Ensure it supports title and editor
            if (post_type_supports($slug, 'title') && post_type_supports($slug, 'editor')) {
                $label = $object->labels->singular_name ?? $object->labels->name ?? $slug;
                $compatible[$slug] = (string) $label;
            }
        }

        // Always ensure 'post' is available if post type exists
        if (empty($compatible) && post_type_exists('post')) {
            $compatible['post'] = __('Post', 'wpcalibrate-rss-importer');
        }

        /**
         * Filter compatible destination post types.
         *
         * @param array<string, string> $compatible Array of slug => label.
         */
        return (array) apply_filters('wpcri_compatible_post_types', $compatible);
    }
}
