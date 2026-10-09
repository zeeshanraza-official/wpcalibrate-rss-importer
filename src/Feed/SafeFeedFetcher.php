<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Feed;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Safe HTTP fetcher for feeds with strict SSRF protection and timeout controls.
 */
final class SafeFeedFetcher {

    private int $timeout;

    public function __construct(int $timeout = 15) {
        $this->timeout = max(5, min(60, $timeout));
    }

    /**
     * Fetch feed content from remote URL safely.
     *
     * @return string|\WP_Error Feed XML content or WP_Error on failure.
     */
    public function fetch(string $url): string|\WP_Error {
        $url = trim($url);

        // 1. Validate URL syntax
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return new \WP_Error(
                'wpcri_invalid_url',
                __('The provided feed URL is invalid or malformed.', 'wpcalibrate-rss-importer')
            );
        }

        // 2. Validate scheme
        $scheme = (string) wp_parse_url($url, PHP_URL_SCHEME);
        if (!in_array(strtolower($scheme), ['http', 'https'], true)) {
            return new \WP_Error(
                'wpcri_invalid_scheme',
                __('Only HTTP and HTTPS feed URLs are supported.', 'wpcalibrate-rss-importer')
            );
        }

        // 3. Validate host against SSRF targets
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        if (empty($host) || !$this->isHostSafe($host)) {
            return new \WP_Error(
                'wpcri_ssrf_blocked',
                __('Requests to localhost, private IP ranges, or internal network endpoints are blocked.', 'wpcalibrate-rss-importer')
            );
        }

        // 4. Validate through WordPress URL safety check if available
        if (function_exists('wp_http_validate_url') && !wp_http_validate_url($url)) {
            $allowLocal = (bool) apply_filters('wpcri_allow_local_requests', false);
            if (!$allowLocal) {
                return new \WP_Error(
                    'wpcri_unsafe_url',
                    __('The feed URL failed WordPress safe URL validation.', 'wpcalibrate-rss-importer')
                );
            }
        }

        // 5. Build request arguments
        $args = [
            'timeout'            => $this->timeout,
            'redirection'        => 5,
            'user-agent'         => 'WPCalibrate-RSS-Importer/' . WPCRI_VERSION . '; ' . home_url(),
            'reject_unsafe_urls' => !(bool) apply_filters('wpcri_allow_local_requests', false),
            'sslverify'          => (bool) apply_filters('https_local_ssl_verify', true),
            'headers'            => [
                'Accept' => 'application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9, */*;q=0.8',
            ],
        ];

        /**
         * Filter feed HTTP request arguments before sending.
         *
         * @param array<string, mixed> $args Request arguments.
         * @param string               $url  Feed URL.
         */
        $args = (array) apply_filters('wpcri_feed_request_args', $args, $url);

        /**
         * Action fired before feed fetch.
         *
         * @param string $url Feed URL.
         */
        do_action('wpcri_before_feed_fetch', $url);

        // 6. Perform safe request
        $response = wp_safe_remote_get($url, $args);

        if (is_wp_error($response)) {
            $errorMsg = $response->get_error_message();
            // Sanitize and avoid exposing internal paths
            return new \WP_Error(
                'wpcri_fetch_error',
                sprintf(
                    /* translators: %s: Error message */
                    __('Failed to reach feed: %s', 'wpcalibrate-rss-importer'),
                    esc_html($errorMsg)
                )
            );
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        if ($statusCode < 200 || $statusCode >= 400) {
            return new \WP_Error(
                'wpcri_http_error',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __('Feed server responded with HTTP status code %d.', 'wpcalibrate-rss-importer'),
                    $statusCode
                )
            );
        }

        $body = wp_remote_retrieve_body($response);
        if (trim($body) === '') {
            return new \WP_Error(
                'wpcri_empty_feed',
                __('Feed returned an empty response.', 'wpcalibrate-rss-importer')
            );
        }

        /**
         * Action fired after successful feed fetch.
         *
         * @param string $url  Feed URL.
         * @param string $body Response body.
         */
        do_action('wpcri_after_feed_fetch', $url, $body);

        return $body;
    }

    /**
     * Check if a hostname/IP resolves to a safe public internet address (SSRF mitigation).
     */
    public function isHostSafe(string $host): bool {
        // Allow overriding in testing environments
        if ((bool) apply_filters('wpcri_allow_local_requests', false)) {
            return true;
        }

        $lowerHost = strtolower($host);

        // Block known dangerous domain prefixes/suffixes
        if (
            $lowerHost === 'localhost' ||
            str_ends_with($lowerHost, '.localhost') ||
            str_ends_with($lowerHost, '.local') ||
            str_ends_with($lowerHost, '.internal') ||
            str_ends_with($lowerHost, '.arpa') ||
            $lowerHost === 'metadata.google.internal' ||
            $lowerHost === 'instance-data'
        ) {
            return false;
        }

        // If host is an IP or resolves to IP
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $resolved = @gethostbynamel($host);
            if ($resolved === false || empty($resolved)) {
                return true; // Let WP HTTP API handle unresolvable hosts
            }
            $ips = $resolved;
        }

        foreach ($ips as $ip) {
            if (!$this->isPublicIp((string) $ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Verify whether an IP address is a public address and not private, loopback, or link-local.
     */
    public function isPublicIp(string $ip): bool {
        // Filter out private, reserved, and loopback ranges
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
            return false;
        }

        // Explicit link-local check (e.g. AWS/Cloud metadata 169.254.169.254)
        if (str_starts_with($ip, '169.254.')) {
            return false;
        }

        // IPv4 loopback check (127.0.0.0/8)
        if (str_starts_with($ip, '127.')) {
            return false;
        }

        // IPv6 loopback and link-local
        $lowerIp = strtolower($ip);
        if ($lowerIp === '::1' || str_starts_with($lowerIp, 'fe80:') || str_starts_with($lowerIp, 'fc00:') || str_starts_with($lowerIp, 'fd00:')) {
            return false;
        }

        return true;
    }
}
