<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Import;

use WPCalibrate\RSSImporter\Feed\FeedSource;
use WPCalibrate\RSSImporter\Feed\FeedItem;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles content attribution formatting and canonical URL integration.
 */
final class AttributionManager {

    /**
     * Initialize canonical URL filters if on front-end.
     */
    public function init(): void {
        if (!is_admin()) {
            add_filter('get_canonical_url', [$this, 'filterCanonicalUrl'], 20, 2);
            // Yoast SEO hook
            add_filter('wpseo_canonical', [$this, 'filterSeoPluginCanonical'], 20);
            // Rank Math hook
            add_filter('rank_math/frontend/canonical', [$this, 'filterSeoPluginCanonical'], 20);
            // AIOSEO hook
            add_filter('aioseo_canonical_url', [$this, 'filterSeoPluginCanonical'], 20);
        }
    }

    /**
     * Append formatted attribution HTML to post content when enabled.
     */
    public function appendAttribution(string $content, FeedSource $source, FeedItem $item): string {
        $attrConfig = $source->getAttribution();
        if (empty($attrConfig['enabled'])) {
            return $content;
        }

        $template = (string) ($attrConfig['format'] ?? '');
        if (trim($template) === '') {
            $template = FeedSource::defaultAttribution()['format'];
        }

        $sourceTitle = esc_html($source->getName());
        $itemTitle   = esc_html($item->getTitle());
        $itemUrl     = esc_url($item->getPermalink());
        $sourceUrl   = esc_url($source->getUrl());

        $replacements = [
            '{source_title}' => $sourceTitle,
            '{source_url}'   => $itemUrl !== '' ? $itemUrl : $sourceUrl,
            '{item_title}'   => $itemTitle,
            '{item_url}'     => $itemUrl,
        ];

        $rendered = str_replace(array_keys($replacements), array_values($replacements), $template);

        // Enforce rel="noopener noreferrer" for any target="_blank"
        $rendered = preg_replace_callback('/<a\s+([^>]*target=["\']_blank["\'][^>]*)>/i', static function ($matches) {
            $tag = $matches[0];
            if (!str_contains($tag, 'rel=')) {
                return str_replace('>', ' rel="noopener noreferrer">', $tag);
            }
            return $tag;
        }, $rendered);

        // Sanitize with post KSES rules
        $safeHtml = wp_kses_post($rendered ?? '');

        /**
         * Filter generated attribution HTML.
         *
         * @param string     $safeHtml Sanitized attribution HTML.
         * @param FeedSource $source   Feed source.
         * @param FeedItem   $item     Feed item.
         */
        $safeHtml = (string) apply_filters('wpcri_attribution_html', $safeHtml, $source, $item);

        return $content . "\n\n" . $safeHtml;
    }

    /**
     * Record canonical URL metadata on imported post.
     */
    public function recordCanonicalMeta(int $postId, FeedSource $source, FeedItem $item): void {
        if ($source->isUseCanonical() && !empty($item->getPermalink())) {
            update_post_meta($postId, '_wpcri_canonical_url', esc_url_raw($item->getPermalink()));
        } else {
            delete_post_meta($postId, '_wpcri_canonical_url');
        }
    }

    /**
     * WordPress core get_canonical_url filter.
     */
    public function filterCanonicalUrl(string $canonicalUrl, ?\WP_Post $post = null): string {
        $targetPost = $post ?: get_post();
        if (!$targetPost instanceof \WP_Post) {
            return $canonicalUrl;
        }

        $customCanonical = (string) get_post_meta($targetPost->ID, '_wpcri_canonical_url', true);
        if (!empty($customCanonical)) {
            return esc_url($customCanonical);
        }

        return $canonicalUrl;
    }

    /**
     * Canonical filter for third-party SEO plugins (Yoast, Rank Math, AIOSEO).
     */
    public function filterSeoPluginCanonical(mixed $canonical): mixed {
        if (!is_singular()) {
            return $canonical;
        }

        $postId = get_the_ID();
        if (!$postId) {
            return $canonical;
        }

        $customCanonical = (string) get_post_meta($postId, '_wpcri_canonical_url', true);
        if (!empty($customCanonical)) {
            return esc_url($customCanonical);
        }

        return $canonical;
    }
}
