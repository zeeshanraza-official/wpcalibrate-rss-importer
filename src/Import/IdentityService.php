<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Import;

use WPCalibrate\RSSImporter\Feed\FeedSource;
use WPCalibrate\RSSImporter\Feed\FeedItem;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Service responsible for deterministic item identity generation, deduplication, and change detection.
 */
final class IdentityService {

    /**
     * Generate a deterministic unique fingerprint for a feed item within a feed source.
     */
    public function generateFingerprint(FeedSource $source, FeedItem $item): string {
        $sourceId   = $source->getId();
        $guid       = trim($item->getGuid());
        $normUrl    = $this->normalizeUrl($item->getPermalink());

        // Strongest: Source ID + GUID
        if ($guid !== '') {
            return hash('sha256', "source:{$sourceId}|guid:{$guid}");
        }

        // Secondary: Source ID + Normalized URL
        if ($normUrl !== '') {
            return hash('sha256', "source:{$sourceId}|url:{$normUrl}");
        }

        // Fallback: Source ID + Title + Date + Content excerpt hash
        $cleanTitle = strtolower(trim(strip_tags($item->getTitle())));
        $date       = (string) ($item->getDate() ?? '');
        $contentSub = substr(strip_tags($item->getContent()), 0, 200);

        return hash('sha256', "source:{$sourceId}|title:{$cleanTitle}|date:{$date}|content:{$contentSub}");
    }

    /**
     * Compute a content hash to detect changes to an item.
     */
    public function computeContentHash(FeedItem $item): string {
        return md5($item->getTitle() . '|' . $item->getExcerpt() . '|' . $item->getContent());
    }

    /**
     * Find if this feed item has already been imported into WordPress.
     *
     * @return int|null Existing Post ID if found, null otherwise.
     */
    public function findExistingPost(FeedSource $source, FeedItem $item): ?int {
        $sourceId    = $source->getId();
        $guid        = trim($item->getGuid());
        $normUrl     = $this->normalizeUrl($item->getPermalink());
        $fingerprint = $this->generateFingerprint($source, $item);

        // 1. Check by fingerprint (fastest and most specific)
        $existing = $this->queryPostByMeta([
            [
                'key'   => '_wpcri_source_id',
                'value' => (string) $sourceId,
            ],
            [
                'key'   => '_wpcri_item_fingerprint',
                'value' => $fingerprint,
            ],
        ]);

        if ($existing !== null) {
            return $existing;
        }

        // 2. Check by GUID if available
        if ($guid !== '') {
            $existing = $this->queryPostByMeta([
                [
                    'key'   => '_wpcri_source_id',
                    'value' => (string) $sourceId,
                ],
                [
                    'key'   => '_wpcri_item_guid',
                    'value' => $guid,
                ],
            ]);

            if ($existing !== null) {
                return $existing;
            }
        }

        // 3. Check by original URL if available
        if ($normUrl !== '') {
            $existing = $this->queryPostByMeta([
                [
                    'key'   => '_wpcri_source_id',
                    'value' => (string) $sourceId,
                ],
                [
                    'key'   => '_wpcri_original_url',
                    'value' => $normUrl,
                ],
            ]);

            if ($existing !== null) {
                return $existing;
            }
        }

        return null;
    }

    /**
     * Check whether an existing imported post's feed content has changed.
     */
    public function hasItemChanged(int $postId, FeedItem $item): bool {
        $storedHash = (string) get_post_meta($postId, '_wpcri_item_hash', true);
        if ($storedHash === '') {
            return true;
        }

        return $storedHash !== $this->computeContentHash($item);
    }

    /**
     * Record identity metadata against destination post.
     */
    public function recordPostIdentity(int $postId, FeedSource $source, FeedItem $item, string $fingerprint): void {
        $now = current_time('mysql');

        update_post_meta($postId, '_wpcri_source_id', (string) $source->getId());
        update_post_meta($postId, '_wpcri_item_guid', $item->getGuid());
        update_post_meta($postId, '_wpcri_source_url', $source->getUrl());
        update_post_meta($postId, '_wpcri_original_url', $this->normalizeUrl($item->getPermalink()));
        update_post_meta($postId, '_wpcri_item_fingerprint', $fingerprint);
        update_post_meta($postId, '_wpcri_item_hash', $this->computeContentHash($item));

        if (!get_post_meta($postId, '_wpcri_imported_at', true)) {
            update_post_meta($postId, '_wpcri_imported_at', $now);
        }
        update_post_meta($postId, '_wpcri_last_synced_at', $now);
    }

    /**
     * Normalize URL for consistent matching (strip tracking parameters and normalize casing/slashes).
     */
    public function normalizeUrl(string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme'] ?? 'http');
        $host   = strtolower($parts['host']);
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path   = $parts['path'] ?? '/';
        $path   = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        $query = '';
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $queryParams);
            // Strip marketing tracking parameters
            $trackingKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'ref'];
            foreach ($trackingKeys as $key) {
                unset($queryParams[$key]);
            }
            if (!empty($queryParams)) {
                ksort($queryParams);
                $query = '?' . http_build_query($queryParams);
            }
        }

        return "{$scheme}://{$host}{$port}{$path}{$query}";
    }

    /**
     * Query post ID by meta query.
     *
     * @param array<array{key: string, value: string}> $metaQuery
     */
    private function queryPostByMeta(array $metaQuery): ?int {
        $posts = get_posts([
            'post_type'      => 'any',
            'post_status'    => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => $metaQuery,
            'no_found_rows'  => true,
        ]);

        if (!empty($posts) && isset($posts[0])) {
            return (int) $posts[0];
        }

        return null;
    }
}
