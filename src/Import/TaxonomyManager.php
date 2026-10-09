<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Import;

use WPCalibrate\RSSImporter\Feed\FeedSource;
use WPCalibrate\RSSImporter\Feed\FeedItem;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles category, tag, and RSS category mapping to destination WordPress post taxonomies.
 */
final class TaxonomyManager {

    /**
     * Assign configured taxonomies and mapped RSS categories to the destination post.
     */
    public function assignTaxonomies(int $postId, FeedSource $source, FeedItem $item): void {
        $postType = $source->getDestinationPostType();
        $supportedTaxonomies = get_object_taxonomies($postType, 'names');

        // 1. Assign static configured taxonomies
        $configuredTaxonomies = $source->getTaxonomies();
        foreach ($configuredTaxonomies as $taxonomy => $terms) {
            if (!in_array($taxonomy, $supportedTaxonomies, true)) {
                continue;
            }

            if (!empty($terms)) {
                $termIds = array_map('intval', (array) $terms);
                wp_set_object_terms($postId, $termIds, $taxonomy, true);
            }
        }

        // 2. Map dynamic feed categories if enabled
        if ($source->isMapCategories()) {
            $targetTaxonomy = $source->getMappedTaxonomy();
            if (in_array($targetTaxonomy, $supportedTaxonomies, true)) {
                $feedCategories = $item->getCategories();
                $termIds = [];

                foreach ($feedCategories as $catName) {
                    $catName = sanitize_text_field(trim($catName));
                    if ($catName === '') {
                        continue;
                    }

                    $term = get_term_by('name', $catName, $targetTaxonomy);
                    if ($term instanceof \WP_Term) {
                        $termIds[] = (int) $term->term_id;
                    } elseif ($source->isAutoCreateTerms()) {
                        // Create term if auto-create is enabled
                        $newTerm = wp_insert_term($catName, $targetTaxonomy);
                        if (!is_wp_error($newTerm) && isset($newTerm['term_id'])) {
                            $termIds[] = (int) $newTerm['term_id'];
                        }
                    }
                }

                if (!empty($termIds)) {
                    wp_set_object_terms($postId, $termIds, $targetTaxonomy, true);
                }
            }
        }
    }
}
