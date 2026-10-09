<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Filtering;

use WPCalibrate\RSSImporter\Feed\FeedSource;
use WPCalibrate\RSSImporter\Feed\FeedItem;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Evaluates feed items against source-defined include/exclude filtering rules.
 */
final class FilterEngine {

    /**
     * Evaluate feed item against feed source rules.
     */
    public function evaluate(FeedSource $source, FeedItem $item): FilterResult {
        $rules = $source->getFiltering();

        /**
         * Action fired before evaluating filter rules on an item.
         *
         * @param FeedSource $source Feed source.
         * @param FeedItem   $item   Feed item.
         */
        do_action('wpcri_before_filter_evaluation', $source, $item);

        // 1. Date / Age Filter
        $maxAgeDays = (int) ($rules['max_age_days'] ?? 0);
        if ($maxAgeDays > 0) {
            $itemDate = $item->getDate();
            if ($itemDate !== null) {
                $itemTimestamp = strtotime($itemDate);
                $cutoff = time() - ($maxAgeDays * DAY_IN_SECONDS);
                if ($itemTimestamp !== false && $itemTimestamp < $cutoff) {
                    $result = FilterResult::reject(
                        sprintf(
                            /* translators: 1: Item date, 2: Max days */
                            __('Item date (%1$s) is older than max allowed age (%2$d days)', 'wpcalibrate-rss-importer'),
                            $itemDate,
                            $maxAgeDays
                        )
                    );
                    return $this->applyFilterDecision($result, $source, $item);
                }
            }
        }

        // 2. Feed Category Filter
        $catFilter = trim((string) ($rules['category_filter'] ?? ''));
        if ($catFilter !== '') {
            $requiredCats = $this->splitTerms($catFilter);
            if (!empty($requiredCats)) {
                $itemCats = array_map('strtolower', $item->getCategories());
                $matchedCat = false;
                foreach ($requiredCats as $reqCat) {
                    if (in_array(strtolower($reqCat), $itemCats, true)) {
                        $matchedCat = true;
                        break;
                    }
                }
                if (!$matchedCat) {
                    $result = FilterResult::reject(
                        sprintf(
                            /* translators: %s: Required category filter */
                            __('Item categories do not match required category filter (%s)', 'wpcalibrate-rss-importer'),
                            $catFilter
                        )
                    );
                    return $this->applyFilterDecision($result, $source, $item);
                }
            }
        }

        // Determine target text for keyword searching
        $targetField = (string) ($rules['filter_target'] ?? 'title_content');
        $searchText  = $this->extractTargetText($item, $targetField);

        // 3. Exclude Keywords Filter (if any excluded keyword matches -> reject)
        $excludeKeywords = trim((string) ($rules['exclude_keywords'] ?? ''));
        if ($excludeKeywords !== '') {
            $terms = $this->splitTerms($excludeKeywords);
            foreach ($terms as $term) {
                if ($this->textContains($searchText, $term)) {
                    $result = FilterResult::reject(
                        sprintf(
                            /* translators: %s: Excluded keyword */
                            __('Item contains excluded keyword "%s"', 'wpcalibrate-rss-importer'),
                            $term
                        )
                    );
                    return $this->applyFilterDecision($result, $source, $item);
                }
            }
        }

        // 4. Include Keywords Filter
        $includeKeywords = trim((string) ($rules['include_keywords'] ?? ''));
        if ($includeKeywords !== '') {
            $terms     = $this->splitTerms($includeKeywords);
            $matchType = (string) ($rules['keyword_match_type'] ?? 'any');

            if (!empty($terms)) {
                if ($matchType === 'all') {
                    foreach ($terms as $term) {
                        if (!$this->textContains($searchText, $term)) {
                            $result = FilterResult::reject(
                                sprintf(
                                    /* translators: %s: Missing required keyword */
                                    __('Item missing required keyword "%s" (all keywords required)', 'wpcalibrate-rss-importer'),
                                    $term
                                )
                            );
                            return $this->applyFilterDecision($result, $source, $item);
                        }
                    }
                } else {
                    // matchType === 'any'
                    $found = false;
                    foreach ($terms as $term) {
                        if ($this->textContains($searchText, $term)) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $result = FilterResult::reject(
                            sprintf(
                                /* translators: %s: Include keywords list */
                                __('Item contains none of the required keywords: %s', 'wpcalibrate-rss-importer'),
                                $includeKeywords
                            )
                        );
                        return $this->applyFilterDecision($result, $source, $item);
                    }
                }
            }
        }

        return $this->applyFilterDecision(FilterResult::allow(), $source, $item);
    }

    /**
     * Apply filter decision through filter hook.
     */
    private function applyFilterDecision(FilterResult $result, FeedSource $source, FeedItem $item): FilterResult {
        /**
         * Filter item decision.
         *
         * @param FilterResult $result Outcome of the filter check.
         * @param FeedSource   $source Feed source.
         * @param FeedItem     $item   Feed item.
         */
        return apply_filters('wpcri_item_filter_decision', $result, $source, $item);
    }

    /**
     * Extract string text according to target field configuration.
     */
    private function extractTargetText(FeedItem $item, string $targetField): string {
        return match ($targetField) {
            'title'   => $item->getTitle(),
            'content' => strip_tags($item->getContent() . ' ' . $item->getExcerpt()),
            default   => $item->getTitle() . ' ' . strip_tags($item->getContent() . ' ' . $item->getExcerpt()),
        };
    }

    /**
     * Case-insensitive substring search.
     */
    private function textContains(string $haystack, string $needle): bool {
        return mb_stripos($haystack, $needle) !== false;
    }

    /**
     * Split comma, semicolon, or newline separated keywords into clean array.
     *
     * @return array<string>
     */
    private function splitTerms(string $input): array {
        $parts = preg_split('/[\r\n,;]+/', $input);
        if (!is_array($parts)) {
            return [];
        }

        $terms = [];
        foreach ($parts as $part) {
            $trimmed = trim($part);
            if ($trimmed !== '') {
                $terms[] = $trimmed;
            }
        }

        return $terms;
    }
}
