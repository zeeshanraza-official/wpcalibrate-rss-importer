<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Feed;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalized Feed Item representation across RSS 2.0 and Atom formats.
 */
final class FeedItem {

    private string $guid;
    private string $title;
    private string $permalink;
    private ?string $date;
    private string $author;
    private string $excerpt;
    private string $content;
    /** @var array<string> */
    private array $categories;
    /** @var array<array{url: string, type: string, length: int}> */
    private array $enclosures;
    /** @var array<array{url: string, type?: string, width?: int, height?: int}> */
    private array $mediaImages;
    /** @var array<string> */
    private array $contentImages;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = []) {
        $this->guid          = trim((string) ($data['guid'] ?? ''));
        $this->title         = trim((string) ($data['title'] ?? ''));
        $this->permalink     = esc_url_raw(trim((string) ($data['permalink'] ?? '')));
        $this->date          = !empty($data['date']) ? (string) $data['date'] : null;
        $this->author        = sanitize_text_field(trim((string) ($data['author'] ?? '')));
        $this->excerpt       = (string) ($data['excerpt'] ?? '');
        $this->content       = (string) ($data['content'] ?? '');
        $this->categories    = is_array($data['categories'] ?? null) ? array_values(array_filter(array_map('trim', $data['categories']))) : [];
        $this->enclosures    = is_array($data['enclosures'] ?? null) ? $data['enclosures'] : [];
        $this->mediaImages   = is_array($data['media_images'] ?? null) ? $data['media_images'] : [];
        $this->contentImages = is_array($data['content_images'] ?? null) ? $data['content_images'] : [];
    }

    public function getGuid(): string {
        return $this->guid;
    }

    public function getTitle(): string {
        return $this->title;
    }

    public function getPermalink(): string {
        return $this->permalink;
    }

    public function getDate(): ?string {
        return $this->date;
    }

    public function getAuthor(): string {
        return $this->author;
    }

    public function getExcerpt(): string {
        return $this->excerpt;
    }

    public function getContent(): string {
        return $this->content;
    }

    /**
     * @return array<string>
     */
    public function getCategories(): array {
        return $this->categories;
    }

    /**
     * @return array<array{url: string, type: string, length: int}>
     */
    public function getEnclosures(): array {
        return $this->enclosures;
    }

    /**
     * @return array<array{url: string, type?: string, width?: int, height?: int}>
     */
    public function getMediaImages(): array {
        return $this->mediaImages;
    }

    /**
     * @return array<string>
     */
    public function getContentImages(): array {
        return $this->contentImages;
    }

    /**
     * Discover the best candidate image according to priority:
     * 1. Media RSS image
     * 2. Suitable image enclosure
     * 3. First valid image from content
     */
    public function getBestCandidateImageUrl(): ?string {
        // 1. Media RSS
        if (!empty($this->mediaImages)) {
            foreach ($this->mediaImages as $media) {
                if (!empty($media['url']) && filter_var($media['url'], FILTER_VALIDATE_URL)) {
                    return $media['url'];
                }
            }
        }

        // 2. Image Enclosure
        if (!empty($this->enclosures)) {
            foreach ($this->enclosures as $enclosure) {
                if (!empty($enclosure['url']) && filter_var($enclosure['url'], FILTER_VALIDATE_URL)) {
                    $type = strtolower($enclosure['type'] ?? '');
                    if (str_starts_with($type, 'image/') || preg_match('/\.(jpe?g|png|gif|webp)($|\?)/i', $enclosure['url'])) {
                        return $enclosure['url'];
                    }
                }
            }
        }

        // 3. First content image
        if (!empty($this->contentImages)) {
            foreach ($this->contentImages as $url) {
                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    return $url;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return [
            'guid'           => $this->guid,
            'title'          => $this->title,
            'permalink'      => $this->permalink,
            'date'           => $this->date,
            'author'         => $this->author,
            'excerpt'        => $this->excerpt,
            'content'        => $this->content,
            'categories'     => $this->categories,
            'enclosures'     => $this->enclosures,
            'media_images'   => $this->mediaImages,
            'content_images' => $this->contentImages,
        ];
    }
}
