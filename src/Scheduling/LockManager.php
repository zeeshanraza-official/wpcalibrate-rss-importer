<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Scheduling;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Concurrency control and mutual exclusion for feed import execution.
 * Prevents overlapping runs with token ownership and safe stale-lock recovery.
 */
final class LockManager {

    public const DEFAULT_LOCK_TTL = 300; // 5 minutes

    /**
     * Attempt to acquire an exclusive execution lock for a feed source.
     *
     * @return string|null Unique lock token if acquired, null if already locked.
     */
    public static function acquireLock(int $feedId, int $ttl = self::DEFAULT_LOCK_TTL): ?string {
        $optionKey = self::getLockKey($feedId);
        $currentLock = get_option($optionKey);
        $now = time();

        if (is_array($currentLock) && isset($currentLock['expires_at'], $currentLock['token'])) {
            $expiresAt = (int) $currentLock['expires_at'];
            if ($now < $expiresAt) {
                // Lock is active and unexpired
                return null;
            }
            // Stale/expired lock: allowed to recover and overwrite
        }

        $token = wp_generate_password(24, false);
        $lockData = [
            'token'       => $token,
            'acquired_at' => $now,
            'expires_at'  => $now + $ttl,
        ];

        update_option($optionKey, $lockData, false);
        return $token;
    }

    /**
     * Safely release an acquired lock only if token matches.
     *
     * @return bool True if released, false if token mismatch or already cleared.
     */
    public static function releaseLock(int $feedId, string $token): bool {
        $optionKey   = self::getLockKey($feedId);
        $currentLock = get_option($optionKey);

        if (is_array($currentLock) && isset($currentLock['token']) && $currentLock['token'] === $token) {
            delete_option($optionKey);
            return true;
        }

        return false;
    }

    /**
     * Check if a feed source is currently actively locked.
     */
    public static function isLocked(int $feedId): bool {
        $optionKey   = self::getLockKey($feedId);
        $currentLock = get_option($optionKey);

        if (is_array($currentLock) && isset($currentLock['expires_at'])) {
            return time() < (int) $currentLock['expires_at'];
        }

        return false;
    }

    /**
     * Force-release all locks (used on deactivation).
     */
    public static function releaseAllLocks(): void {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wpcri_lock_%'");
    }

    private static function getLockKey(int $feedId): string {
        return "wpcri_lock_{$feedId}";
    }
}
