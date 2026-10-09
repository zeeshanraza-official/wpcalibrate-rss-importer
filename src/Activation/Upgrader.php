<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Activation;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles database and version upgrades idempotently.
 */
final class Upgrader {

    public const SCHEMA_VERSION = '1.0.0';

    /**
     * Check current installed version against plugin version and run migrations if needed.
     */
    public static function checkVersion(): void {
        $installedVersion = (string) get_option('wpcri_version', '');
        $installedSchema  = (string) get_option('wpcri_db_version', '');

        if ($installedVersion === WPCRI_VERSION && $installedSchema === self::SCHEMA_VERSION) {
            return;
        }

        // Run migrations if upgrading from an older version
        self::runMigrations($installedSchema);

        update_option('wpcri_version', WPCRI_VERSION, false);
        update_option('wpcri_db_version', self::SCHEMA_VERSION, false);
    }

    /**
     * Run sequential migrations idempotently.
     */
    private static function runMigrations(string $fromSchema): void {
        // Version 1.0.0 initial setup
        if (empty($fromSchema)) {
            // Initial install or pre-schema version
            return;
        }

        // Future version migration hooks will go here
        /**
         * Action fired during WPCalibrate RSS Importer schema upgrade.
         *
         * @param string $fromSchema Previous schema version.
         * @param string $toSchema   Target schema version.
         */
        do_action('wpcri_upgrade_schema', $fromSchema, self::SCHEMA_VERSION);
    }
}
