<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Provides WPCalibrate support information, links, and system diagnostics metadata.
 */
final class SupportManager {

    public const SUPPORT_EMAIL = 'support@wpcalibrate.com';
    public const WEBSITE_URL    = 'https://wpcalibrate.com';
    public const MARKETPLACE    = 'https://marketplace.wpcalibrate.com/';
    public const PHONE_WHATSAPP = '+447474795976';

    /**
     * Get system environment report for diagnostics and support inquiries.
     *
     * @return array<string, string>
     */
    public static function getSystemDiagnostics(): array {
        global $wp_version, $wpdb;

        return [
            'plugin_version'   => WPCRI_VERSION,
            'wordpress_version' => (string) $wp_version,
            'php_version'       => PHP_VERSION,
            'server_software'   => sanitize_text_field($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'),
            'mysql_version'     => $wpdb->db_version(),
            'max_execution_time'=> (string) ini_get('max_execution_time'),
            'memory_limit'      => (string) ini_get('memory_limit'),
            'curl_version'      => function_exists('curl_version') ? (string) (curl_version()['version'] ?? 'Available') : 'Not available',
            'simplexml_enabled' => extension_loaded('simplexml') ? 'Yes' : 'No',
            'dom_enabled'       => extension_loaded('dom') ? 'Yes' : 'No',
            'wp_cron_disabled'  => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'Yes' : 'No',
            'multisite'         => is_multisite() ? 'Yes' : 'No',
        ];
    }
}
