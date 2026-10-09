<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Scheduling;

use WPCalibrate\RSSImporter\Settings\SettingsManager;
use WPCalibrate\RSSImporter\Logging\Logger;
use WPCalibrate\RSSImporter\Feed\FeedRepository;
use WPCalibrate\RSSImporter\Feed\FeedSource;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles custom cron intervals, cron registration, and the import dispatcher.
 */
final class ScheduleManager {

    public const CRON_HOOK_DISPATCHER = 'wpcri_cron_dispatcher_event';

    private SettingsManager $settingsManager;
    private Logger $logger;

    public function __construct(SettingsManager $settingsManager, Logger $logger) {
        $this->settingsManager = $settingsManager;
        $this->logger          = $logger;
    }

    public function init(): void {
        // Register custom cron intervals
        add_filter('cron_schedules', [$this, 'addCustomIntervals']);

        // Dispatcher action hook
        add_action(self::CRON_HOOK_DISPATCHER, [$this, 'dispatchScheduledImports']);
    }

    /**
     * Register custom 15-minute and 30-minute intervals.
     *
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public function addCustomIntervals(array $schedules): array {
        if (!isset($schedules['wpcri_15min'])) {
            $schedules['wpcri_15min'] = [
                'interval' => 900,
                'display'  => __('Every 15 Minutes', 'wpcalibrate-rss-importer'),
            ];
        }

        if (!isset($schedules['wpcri_30min'])) {
            $schedules['wpcri_30min'] = [
                'interval' => 1800,
                'display'  => __('Every 30 Minutes', 'wpcalibrate-rss-importer'),
            ];
        }

        return $schedules;
    }

    /**
     * Schedule the main recurrent dispatcher if not already scheduled.
     */
    public static function scheduleDispatcher(): void {
        if (!wp_next_scheduled(self::CRON_HOOK_DISPATCHER)) {
            wp_schedule_event(time() + 60, 'wpcri_15min', self::CRON_HOOK_DISPATCHER);
        }
    }

    /**
     * Clear all scheduled events.
     */
    public static function clearAllScheduledEvents(): void {
        $timestamp = wp_next_scheduled(self::CRON_HOOK_DISPATCHER);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK_DISPATCHER);
        }
    }

    /**
     * Dispatcher callback executed by WP-Cron.
     */
    public function dispatchScheduledImports(): void {
        // Daily log pruning
        $this->logger->prune();

        $repo  = new FeedRepository();
        $feeds = $repo->getEnabledScheduledFeeds();

        if (empty($feeds)) {
            return;
        }

        $importManager = \WPCalibrate\RSSImporter\Plugin::getInstance()->getImportManager();

        foreach ($feeds as $feed) {
            if ($this->isFeedDue($feed)) {
                $importManager->importBatch($feed, true);
            }
        }
    }

    /**
     * Check if a feed source is due for execution.
     */
    public function isFeedDue(FeedSource $feed): bool {
        if (!$feed->isEnabled() || $feed->getSchedule() === 'manual') {
            return false;
        }

        $lastAttempt = $feed->getLastAttemptedRun();
        if ($lastAttempt === null) {
            return true;
        }

        $lastTime = strtotime($lastAttempt);
        if ($lastTime === false) {
            return true;
        }

        $intervalSeconds = $this->getIntervalSeconds($feed->getSchedule());
        return (time() - $lastTime) >= ($intervalSeconds - 30); // 30s grace window
    }

    /**
     * Map schedule key to duration in seconds.
     */
    public function getIntervalSeconds(string $schedule): int {
        return match ($schedule) {
            '15min'      => 900,
            '30min'      => 1800,
            'hourly'     => 3600,
            'twicedaily' => 43200,
            'daily'      => 86400,
            default      => 3600,
        };
    }
}
