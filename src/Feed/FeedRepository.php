<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Feed;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Repository for managing FeedSource persistence via WordPress custom post type 'wpcri_feed'.
 */
final class FeedRepository {

    public const POST_TYPE = 'wpcri_feed';

    /**
     * Register private custom post type for feed source storage.
     */
    public function registerPostType(): void {
        register_post_type(self::POST_TYPE, [
            'labels'              => [
                'name'          => __('RSS Feeds', 'wpcalibrate-rss-importer'),
                'singular_name' => __('RSS Feed', 'wpcalibrate-rss-importer'),
            ],
            'public'              => false,
            'show_ui'             => false,
            'show_in_menu'        => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
            'can_export'          => true,
            'supports'            => ['title'],
            'rewrite'             => false,
            'query_var'           => false,
        ]);
    }

    /**
     * Fetch a feed source by ID.
     */
    public function get(int $id): ?FeedSource {
        if ($id <= 0) {
            return null;
        }

        $post = get_post($id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            return null;
        }

        return $this->hydrate($post);
    }

    /**
     * Fetch all feed sources with optional filtering and pagination.
     *
     * @param array<string, mixed> $args
     * @return array<FeedSource>
     */
    public function getAll(array $args = []): array {
        $paged   = max(1, (int) ($args['paged'] ?? 1));
        $perPage = max(1, min(100, (int) ($args['per_page'] ?? 20)));
        $search  = sanitize_text_field((string) ($args['s'] ?? ''));

        $queryArgs = [
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => $perPage,
            'paged'          => $paged,
            'orderby'        => sanitize_key((string) ($args['orderby'] ?? 'date')),
            'order'          => strtoupper((string) ($args['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC',
        ];

        if (!empty($search)) {
            $queryArgs['s'] = $search;
        }

        $query = new \WP_Query($queryArgs);
        $feeds = [];

        if ($query->have_posts()) {
            foreach ($query->posts as $post) {
                if ($post instanceof \WP_Post) {
                    $feeds[] = $this->hydrate($post);
                }
            }
        }

        return $feeds;
    }

    /**
     * Count total feed sources.
     *
     * @param array<string, mixed> $args
     */
    public function count(array $args = []): int {
        $search = sanitize_text_field((string) ($args['s'] ?? ''));

        $queryArgs = [
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ];

        if (!empty($search)) {
            $queryArgs['s'] = $search;
        }

        $query = new \WP_Query($queryArgs);
        return (int) $query->found_posts;
    }

    /**
     * Fetch all enabled scheduled feeds.
     *
     * @return array<FeedSource>
     */
    public function getEnabledScheduledFeeds(): array {
        $query = new \WP_Query([
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'     => '_wpcri_enabled',
                    'value'   => '1',
                    'compare' => '=',
                ],
            ],
        ]);

        $feeds = [];
        if ($query->have_posts()) {
            foreach ($query->posts as $post) {
                if ($post instanceof \WP_Post) {
                    $source = $this->hydrate($post);
                    if ($source->getSchedule() !== 'manual') {
                        $feeds[] = $source;
                    }
                }
            }
        }

        return $feeds;
    }

    /**
     * Save a feed source (insert or update).
     *
     * @return int Saved Feed ID, or 0 on failure.
     */
    public function save(FeedSource $source): int {
        $postData = [
            'post_title'  => $source->getName(),
            'post_type'   => self::POST_TYPE,
            'post_status' => 'publish',
        ];

        if ($source->getId() > 0) {
            $postData['ID'] = $source->getId();
            $postId = wp_update_post($postData);
        } else {
            $postId = wp_insert_post($postData);
        }

        if (is_wp_error($postId) || $postId === 0) {
            return 0;
        }

        $this->saveMeta((int) $postId, $source);
        return (int) $postId;
    }

    /**
     * Delete a feed source.
     * Deleting a feed source does not delete previously imported posts.
     */
    public function delete(int $id): bool {
        $post = get_post($id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            return false;
        }

        $result = wp_delete_post($id, true);
        return (bool) $result;
    }

    /**
     * Update run statistics after an import run.
     */
    public function updateRunStats(
        int $id,
        bool $success,
        int $imported,
        int $skipped,
        int $failed,
        ?string $error = null
    ): void {
        $now = current_time('mysql');
        update_post_meta($id, '_wpcri_last_attempted_run', $now);

        if ($success) {
            update_post_meta($id, '_wpcri_last_successful_run', $now);
            delete_post_meta($id, '_wpcri_last_error');
        } elseif ($error !== null) {
            update_post_meta($id, '_wpcri_last_error', sanitize_text_field($error));
        }

        $currentImported = (int) get_post_meta($id, '_wpcri_total_imported', true);
        $currentSkipped  = (int) get_post_meta($id, '_wpcri_total_skipped', true);
        $currentFailed   = (int) get_post_meta($id, '_wpcri_total_failed', true);

        update_post_meta($id, '_wpcri_total_imported', $currentImported + $imported);
        update_post_meta($id, '_wpcri_total_skipped', $currentSkipped + $skipped);
        update_post_meta($id, '_wpcri_total_failed', $currentFailed + $failed);
    }

    /**
     * Hydrate a FeedSource entity from a WP_Post and its post meta.
     */
    private function hydrate(\WP_Post $post): FeedSource {
        $id = $post->ID;

        $filtering   = get_post_meta($id, '_wpcri_filtering', true);
        $imageConfig = get_post_meta($id, '_wpcri_image_config', true);
        $attribution = get_post_meta($id, '_wpcri_attribution', true);
        $taxonomies  = get_post_meta($id, '_wpcri_taxonomies', true);

        return new FeedSource([
            'id'                    => $id,
            'name'                  => $post->post_title,
            'url'                   => (string) get_post_meta($id, '_wpcri_url', true),
            'enabled'               => (bool) get_post_meta($id, '_wpcri_enabled', true),
            'destination_post_type' => (string) (get_post_meta($id, '_wpcri_destination_post_type', true) ?: 'post'),
            'destination_status'    => (string) (get_post_meta($id, '_wpcri_destination_status', true) ?: 'draft'),
            'author_id'             => (int) (get_post_meta($id, '_wpcri_author_id', true) ?: 1),
            'taxonomies'            => is_array($taxonomies) ? $taxonomies : [],
            'map_categories'        => (bool) get_post_meta($id, '_wpcri_map_categories', true),
            'mapped_taxonomy'       => (string) (get_post_meta($id, '_wpcri_mapped_taxonomy', true) ?: 'category'),
            'auto_create_terms'     => (bool) get_post_meta($id, '_wpcri_auto_create_terms', true),
            'filtering'             => is_array($filtering) ? $filtering : FeedSource::defaultFiltering(),
            'image_config'          => is_array($imageConfig) ? $imageConfig : FeedSource::defaultImageConfig(),
            'attribution'           => is_array($attribution) ? $attribution : FeedSource::defaultAttribution(),
            'use_canonical'         => (bool) get_post_meta($id, '_wpcri_use_canonical', true),
            'update_mode'           => (string) (get_post_meta($id, '_wpcri_update_mode', true) ?: 'never'),
            'date_behavior'         => (string) (get_post_meta($id, '_wpcri_date_behavior', true) ?: 'feed_date'),
            'schedule'              => (string) (get_post_meta($id, '_wpcri_schedule', true) ?: 'hourly'),
            'batch_size'            => (int) (get_post_meta($id, '_wpcri_batch_size', true) ?: 10),
            'last_successful_run'   => get_post_meta($id, '_wpcri_last_successful_run', true) ?: null,
            'last_attempted_run'    => get_post_meta($id, '_wpcri_last_attempted_run', true) ?: null,
            'total_imported'        => (int) get_post_meta($id, '_wpcri_total_imported', true),
            'total_skipped'         => (int) get_post_meta($id, '_wpcri_total_skipped', true),
            'total_failed'          => (int) get_post_meta($id, '_wpcri_total_failed', true),
            'last_error'            => get_post_meta($id, '_wpcri_last_error', true) ?: null,
        ]);
    }

    /**
     * Save metadata for a feed source.
     */
    private function saveMeta(int $postId, FeedSource $source): void {
        update_post_meta($postId, '_wpcri_url', $source->getUrl());
        update_post_meta($postId, '_wpcri_enabled', $source->isEnabled() ? '1' : '0');
        update_post_meta($postId, '_wpcri_destination_post_type', $source->getDestinationPostType());
        update_post_meta($postId, '_wpcri_destination_status', $source->getDestinationStatus());
        update_post_meta($postId, '_wpcri_author_id', $source->getAuthorId());
        update_post_meta($postId, '_wpcri_taxonomies', $source->getTaxonomies());
        update_post_meta($postId, '_wpcri_map_categories', $source->isMapCategories() ? '1' : '0');
        update_post_meta($postId, '_wpcri_mapped_taxonomy', $source->getMappedTaxonomy());
        update_post_meta($postId, '_wpcri_auto_create_terms', $source->isAutoCreateTerms() ? '1' : '0');
        update_post_meta($postId, '_wpcri_filtering', $source->getFiltering());
        update_post_meta($postId, '_wpcri_image_config', $source->getImageConfig());
        update_post_meta($postId, '_wpcri_attribution', $source->getAttribution());
        update_post_meta($postId, '_wpcri_use_canonical', $source->isUseCanonical() ? '1' : '0');
        update_post_meta($postId, '_wpcri_update_mode', $source->getUpdateMode());
        update_post_meta($postId, '_wpcri_date_behavior', $source->getDateBehavior());
        update_post_meta($postId, '_wpcri_schedule', $source->getSchedule());
        update_post_meta($postId, '_wpcri_batch_size', $source->getBatchSize());
    }
}
