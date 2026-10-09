<?php
/**
 * Fired when WPCalibrate RSS Importer is uninstalled.
 */

declare(strict_types=1);

// Enforce genuine WordPress uninstallation context
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Check opt-in data retention setting
$settings = get_option('wpcri_settings', []);
$deleteData = !empty($settings['delete_data_on_uninstall']);

if (!$deleteData) {
    // Default is OFF: preserve feeds, settings, imported posts, and metadata
    return;
}

global $wpdb;

// 1. Delete all feed sources owned by this plugin (wpcri_feed posts and their meta)
$feedPosts = get_posts([
    'post_type'      => 'wpcri_feed',
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
]);

foreach ($feedPosts as $feedPostId) {
    wp_delete_post((int) $feedPostId, true);
}

// 2. Clear plugin options
delete_option('wpcri_settings');
delete_option('wpcri_activity_logs');
delete_option('wpcri_license_data');
delete_option('wpcri_version');
delete_option('wpcri_db_version');
delete_option('wpcri_installed_at');

// 3. Clear transient and lock options
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wpcri_lock_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpcri_%' OR option_name LIKE '_transient_timeout_wpcri_%'");

// 4. Unschedue any remaining cron events
$timestamp = wp_next_scheduled('wpcri_cron_dispatcher_event');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'wpcri_cron_dispatcher_event');
}

// Note: Imported WordPress posts and imported media items are intentionally PRESERVED
// to safeguard the site owner's published content.
