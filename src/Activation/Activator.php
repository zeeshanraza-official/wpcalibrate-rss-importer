<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Activation;

use WPCalibrate\RSSImporter\Settings\SettingsManager;
use WPCalibrate\RSSImporter\Scheduling\ScheduleManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles plugin activation routines.
 */
final class Activator {

    /**
     * Execute activation tasks.
     */
    public static function activate(): void {
        // Enforce PHP version check
        if (version_compare(PHP_VERSION, WPCRI_MIN_PHP, '<')) {
            deactivate_plugins(WPCRI_PLUGIN_BASENAME);
            wp_die(
                esc_html(
                    sprintf(
                        /* translators: 1: Plugin name, 2: Required PHP version, 3: Current PHP version */
                        __('%1$s requires PHP version %2$s or higher. Your server is running PHP version %3$s.', 'wpcalibrate-rss-importer'),
                        'WPCalibrate RSS Importer',
                        WPCRI_MIN_PHP,
                        PHP_VERSION
                    )
                )
            );
        }

        // Set default plugin options if not already set (preserve existing settings)
        SettingsManager::initializeDefaults();

        // Record installed version and schema version
        $currentVersion = get_option('wpcri_version');
        if (empty($currentVersion)) {
            update_option('wpcri_version', WPCRI_VERSION, false);
            update_option('wpcri_db_version', Upgrader::SCHEMA_VERSION, false);
            update_option('wpcri_installed_at', current_time('mysql'), false);
        }

        // Register custom post type so rewrites or initial object state exist
        $feedRepo = new \WPCalibrate\RSSImporter\Feed\FeedRepository();
        $feedRepo->registerPostType();

        // Schedule cron dispatcher
        ScheduleManager::scheduleDispatcher();

        /**
         * Action fired after WPCalibrate RSS Importer activation.
         */
        do_action('wpcri_activated');
    }
}
