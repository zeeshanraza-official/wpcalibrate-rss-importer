<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Feed;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Robust RSS 2.0 and Atom XML parser with XXE protection and Media RSS support.
 */
final class FeedParser {

    /**
     * Parse feed XML string into feed metadata and normalized FeedItems.
     *
     * @return array{title: string, description: string, link: string, items: array<FeedItem>}|\WP_Error
     */
    public function parse(string $xmlContent): array|\WP_Error {
        $xmlContent = trim($xmlContent);
        if ($xmlContent === '') {
            return new \WP_Error('wpcri_empty_xml', __('Feed content is empty.', 'wpcalibrate-rss-importer'));
        }

        // Disable entity loader issues and enable internal errors
        $prevErrors = libxml_use_internal_errors(true);

        try {
            // Load via SimpleXML with LIBXML_NONET for XXE protection
            $xml = simplexml_load_string(
                $xmlContent,
                'SimpleXMLElement',
                LIBXML_NONET | LIBXML_NOCDATA
            );

            if ($xml === false) {
                $xmlErrors = libxml_get_errors();
                libxml_clear_errors();
                $firstError = !empty($xmlErrors) ? $xmlErrors[0]->message : __('Unknown XML parsing error', 'wpcalibrate-rss-importer');

                return new \WP_Error(
                    'wpcri_malformed_xml',
                    sprintf(
                        /* translators: %s: XML error message */
                        __('Failed to parse feed XML: %s', 'wpcalibrate-rss-importer'),
                        trim(esc_html($firstError))
                    )
                );
            }

            // Detect feed type: RSS 2.0 / RSS 1.0 or Atom
            $rootName = strtolower($xml->getName());

            if ($rootName === 'feed') {
                return $this->parseAtomFeed($xml);
            }

            if ($rootName === 'rss' || $rootName === 'rdf') {
                return $this->parseRssFeed($xml);
            }

            // Fallback: check if channel or entry exists directly
            if (isset($xml->channel)) {
                return $this->parseRssFeed($xml);
            }

            if (isset($xml->entry)) {
                return $this->parseAtomFeed($xml);
            }

            return new \WP_Error(
                'wpcri_unsupported_feed_format',
                __('The XML document is neither a recognized RSS nor an Atom feed.', 'wpcalibrate-rss-importer')
            );
        } finally {
            libxml_use_internal_errors($prevErrors);
        }
    }

    /**
     * Parse RSS 2.0 / 1.0 feed.
     *
     * @return array{title: string, description: string, link: string, items: array<FeedItem>}
     */
    private function parseRssFeed(\SimpleXMLElement $xml): array {
        $channel = $xml->channel ?? $xml;

        $feedTitle       = (string) ($channel->title ?? '');
        $feedDescription = (string) ($channel->description ?? '');
        $feedLink        = (string) ($channel->link ?? '');

        $items = [];
        $rawItems = $channel->item ?? $xml->item ?? [];

        foreach ($rawItems as $item) {
            $namespaces = $item->getNamespaces(true);

            // Namespaces
            $contentNs = $namespaces['content'] ?? 'http://purl.org/rss/1.0/modules/content/';
            $mediaNs   = $namespaces['media'] ?? 'http://search.yahoo.com/mrss/';
            $dcNs      = $namespaces['dc'] ?? 'http://purl.org/dc/elements/1.1/';

            $contentChildren = $item->children($contentNs);
            $mediaChildren   = $item->children($mediaNs);
            $dcChildren      = $item->children($dcNs);

            // GUID
            $guid = (string) ($item->guid ?? '');

            // Title
            $title = (string) ($item->title ?? '');

            // Link
            $link = (string) ($item->link ?? '');

            // Date
            $pubDate = (string) ($item->pubDate ?? $dcChildren->date ?? '');
            $normalizedDate = $this->normalizeDate($pubDate);

            // Author
            $author = (string) ($item->author ?? $dcChildren->creator ?? '');

            // Description / Excerpt
            $description = (string) ($item->description ?? '');

            // Content
            $content = (string) ($contentChildren->encoded ?? '');
            if (empty($content)) {
                $content = $description;
            }

            // Categories
            $categories = [];
            if (isset($item->category)) {
                foreach ($item->category as $cat) {
                    $catVal = trim((string) $cat);
                    if ($catVal !== '') {
                        $categories[] = $catVal;
                    }
                }
            }

            // Enclosures
            $enclosures = [];
            if (isset($item->enclosure)) {
                foreach ($item->enclosure as $enc) {
                    $attributes = $enc->attributes();
                    $url = (string) ($attributes['url'] ?? '');
                    if ($url !== '') {
                        $enclosures[] = [
                            'url'    => $url,
                            'type'   => (string) ($attributes['type'] ?? ''),
                            'length' => (int) ($attributes['length'] ?? 0),
                        ];
                    }
                }
            }

            // Media RSS elements
            $mediaImages = [];
            if (isset($mediaChildren->content)) {
                foreach ($mediaChildren->content as $media) {
                    $attributes = $media->attributes();
                    $url    = (string) ($attributes['url'] ?? '');
                    $medium = strtolower((string) ($attributes['medium'] ?? ''));
                    $type   = strtolower((string) ($attributes['type'] ?? ''));

                    if ($url !== '' && ($medium === 'image' || str_starts_with($type, 'image/') || empty($medium))) {
                        $mediaImages[] = [
                            'url'    => $url,
                            'type'   => $type,
                            'width'  => (int) ($attributes['width'] ?? 0),
                            'height' => (int) ($attributes['height'] ?? 0),
                        ];
                    }
                }
            }
            if (isset($mediaChildren->thumbnail)) {
                foreach ($mediaChildren->thumbnail as $thumb) {
                    $attributes = $thumb->attributes();
                    $url = (string) ($attributes['url'] ?? '');
                    if ($url !== '') {
                        $mediaImages[] = [
                            'url'    => $url,
                            'type'   => 'image/jpeg',
                            'width'  => (int) ($attributes['width'] ?? 0),
                            'height' => (int) ($attributes['height'] ?? 0),
                        ];
                    }
                }
            }

            // Content images
            $contentImages = $this->extractImagesFromHtml($content);

            $feedItem = new FeedItem([
                'guid'           => $guid,
                'title'          => $title,
                'permalink'      => $link,
                'date'           => $normalizedDate,
                'author'         => $author,
                'excerpt'        => $description,
                'content'        => $content,
                'categories'     => $categories,
                'enclosures'     => $enclosures,
                'media_images'   => $mediaImages,
                'content_images' => $contentImages,
            ]);

            /**
             * Filter parsed feed item.
             *
             * @param FeedItem          $feedItem Parsed feed item.
             * @param \SimpleXMLElement $item     Raw item XML element.
             */
            $items[] = apply_filters('wpcri_parsed_feed_item', $feedItem, $item);
        }

        return [
            'title'       => $feedTitle,
            'description' => $feedDescription,
            'link'        => $feedLink,
            'items'       => $items,
        ];
    }

    /**
     * Parse Atom feed.
     *
     * @return array{title: string, description: string, link: string, items: array<FeedItem>}
     */
    private function parseAtomFeed(\SimpleXMLElement $xml): array {
        $feedTitle       = (string) ($xml->title ?? '');
        $feedDescription = (string) ($xml->subtitle ?? '');
        $feedLink        = '';

        if (isset($xml->link)) {
            foreach ($xml->link as $linkEl) {
                $attrs = $linkEl->attributes();
                $rel   = (string) ($attrs['rel'] ?? 'alternate');
                if ($rel === 'alternate' || empty($feedLink)) {
                    $feedLink = (string) ($attrs['href'] ?? '');
                }
            }
        }

        $items = [];
        $entries = $xml->entry ?? [];

        foreach ($entries as $entry) {
            $guid = (string) ($entry->id ?? '');
            $title = (string) ($entry->title ?? '');

            // Permalink
            $permalink = '';
            if (isset($entry->link)) {
                foreach ($entry->link as $linkEl) {
                    $attrs = $linkEl->attributes();
                    $rel   = (string) ($attrs['rel'] ?? 'alternate');
                    if ($rel === 'alternate' || empty($permalink)) {
                        $permalink = (string) ($attrs['href'] ?? '');
                    }
                }
            }

            // Date
            $rawDate = (string) ($entry->updated ?? $entry->published ?? '');
            $normalizedDate = $this->normalizeDate($rawDate);

            // Author
            $author = '';
            if (isset($entry->author->name)) {
                $author = (string) $entry->author->name;
            }

            // Excerpt
            $excerpt = (string) ($entry->summary ?? '');

            // Content
            $content = (string) ($entry->content ?? '');
            if (empty($content)) {
                $content = $excerpt;
            }

            // Categories
            $categories = [];
            if (isset($entry->category)) {
                foreach ($entry->category as $cat) {
                    $attrs = $cat->attributes();
                    $term  = (string) ($attrs['term'] ?? $attrs['label'] ?? (string) $cat);
                    $term  = trim($term);
                    if ($term !== '') {
                        $categories[] = $term;
                    }
                }
            }

            // Enclosures
            $enclosures = [];
            if (isset($entry->link)) {
                foreach ($entry->link as $linkEl) {
                    $attrs = $linkEl->attributes();
                    $rel   = (string) ($attrs['rel'] ?? '');
                    if ($rel === 'enclosure') {
                        $url = (string) ($attrs['href'] ?? '');
                        if ($url !== '') {
                            $enclosures[] = [
                                'url'    => $url,
                                'type'   => (string) ($attrs['type'] ?? ''),
                                'length' => (int) ($attrs['length'] ?? 0),
                            ];
                        }
                    }
                }
            }

            $contentImages = $this->extractImagesFromHtml($content);

            $feedItem = new FeedItem([
                'guid'           => $guid,
                'title'          => $title,
                'permalink'      => $permalink,
                'date'           => $normalizedDate,
                'author'         => $author,
                'excerpt'        => $excerpt,
                'content'        => $content,
                'categories'     => $categories,
                'enclosures'     => $enclosures,
                'media_images'   => [],
                'content_images' => $contentImages,
            ]);

            $items[] = apply_filters('wpcri_parsed_feed_item', $feedItem, $entry);
        }

        return [
            'title'       => $feedTitle,
            'description' => $feedDescription,
            'link'        => $feedLink,
            'items'       => $items,
        ];
    }

    /**
     * Normalize feed date string into MySQL datetime format Y-m-d H:i:s.
     */
    public function normalizeDate(string $rawDate): ?string {
        $rawDate = trim($rawDate);
        if ($rawDate === '') {
            return null;
        }

        $timestamp = strtotime($rawDate);
        if ($timestamp === false || $timestamp <= 0) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Extract <img> src URLs from HTML content.
     *
     * @return array<string>
     */
    public function extractImagesFromHtml(string $html): array {
        if (trim($html) === '') {
            return [];
        }

        $images = [];
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $imgUrl) {
                $imgUrl = trim($imgUrl);
                if (filter_var($imgUrl, FILTER_VALIDATE_URL) && !in_array($imgUrl, $images, true)) {
                    $images[] = $imgUrl;
                }
            }
        }

        return $images;
    }
}
