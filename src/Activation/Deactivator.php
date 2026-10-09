<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Activation;

use WPCalibrate\RSSImporter\Scheduling\ScheduleManager;
use WPCalibrate\RSSImporter\Scheduling\LockManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles plugin deactivation routines.
 */
final class Deactivator {

    /**
     * Execute deactivation tasks.
     * Clears scheduled cron jobs and temporary locks without touching user data or imported content.
     */
    public static function deactivate(): void {
        // Clear all scheduled events owned by this plugin
        ScheduleManager::clearAllScheduledEvents();

        // Release any active locks
        LockManager::releaseAllLocks();

        /**
         * Action fired after WPCalibrate RSS Importer deactivation.
         */
        do_action('wpcri_deactivated');
    }
}
