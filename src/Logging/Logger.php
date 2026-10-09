<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Logging;

use WPCalibrate\RSSImporter\Settings\SettingsManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Diagnostic event logger with bounded storage, severities, and retention pruning.
 * Uses non-autoloaded option storage to prevent memory overhead on normal requests.
 */
final class Logger {

    public const OPTION_LOGS = 'wpcri_activity_logs';
    public const MAX_ENTRIES = 1000;

    private SettingsManager $settingsManager;

    public function __construct(SettingsManager $settingsManager) {
        $this->settingsManager = $settingsManager;
    }

    /**
     * Log an informational event.
     *
     * @param array<string, mixed> $context
     */
    public function info(string $message, int $sourceId = 0, array $context = []): void {
        $this->log('info', $message, $sourceId, $context);
    }

    /**
     * Log a success event.
     *
     * @param array<string, mixed> $context
     */
    public function success(string $message, int $sourceId = 0, array $context = []): void {
        $this->log('success', $message, $sourceId, $context);
    }

    /**
     * Log a warning event.
     *
     * @param array<string, mixed> $context
     */
    public function warning(string $message, int $sourceId = 0, array $context = []): void {
        $this->log('warning', $message, $sourceId, $context);
    }

    /**
     * Log an error event.
     *
     * @param array<string, mixed> $context
     */
    public function error(string $message, int $sourceId = 0, array $context = []): void {
        $this->log('error', $message, $sourceId, $context);
    }

    /**
     * Record a log entry with sanitized context.
     *
     * @param array<string, mixed> $context
     */
    public function log(string $severity, string $message, int $sourceId = 0, array $context = []): void {
        $cleanContext = $this->sanitizeContext($context);

        $entry = [
            'id'        => wp_generate_password(12, false),
            'timestamp' => current_time('mysql'),
            'time'      => time(),
            'severity'  => in_array($severity, ['info', 'success', 'warning', 'error'], true) ? $severity : 'info',
            'source_id' => $sourceId,
            'message'   => sanitize_text_field($message),
            'context'   => $cleanContext,
        ];

        $logs = $this->getRawLogs();
        array_unshift($logs, $entry);

        // Cap to MAX_ENTRIES
        if (count($logs) > self::MAX_ENTRIES) {
            $logs = array_slice($logs, 0, self::MAX_ENTRIES);
        }

        update_option(self::OPTION_LOGS, $logs, false);
    }

    /**
     * Fetch logs with optional filtering and pagination.
     *
     * @param array<string, mixed> $args
     * @return array{items: array<array<string, mixed>>, total: int}
     */
    public function getLogs(array $args = []): array {
        $logs = $this->getRawLogs();

        $severity = sanitize_key((string) ($args['severity'] ?? ''));
        $sourceId = (int) ($args['source_id'] ?? 0);
        $search   = strtolower(trim((string) ($args['search'] ?? '')));

        if (!empty($severity) || $sourceId > 0 || !empty($search)) {
            $logs = array_filter($logs, static function (array $item) use ($severity, $sourceId, $search): bool {
                if (!empty($severity) && ($item['severity'] ?? '') !== $severity) {
                    return false;
                }
                if ($sourceId > 0 && (int) ($item['source_id'] ?? 0) !== $sourceId) {
                    return false;
                }
                if (!empty($search)) {
                    $msg = strtolower((string) ($item['message'] ?? ''));
                    if (!str_contains($msg, $search)) {
                        return false;
                    }
                }
                return true;
            });
        }

        $total   = count($logs);
        $paged   = max(1, (int) ($args['paged'] ?? 1));
        $perPage = max(1, min(100, (int) ($args['per_page'] ?? 25)));
        $offset  = ($paged - 1) * $perPage;

        $pagedItems = array_slice(array_values($logs), $offset, $perPage);

        return [
            'items' => $pagedItems,
            'total' => $total,
        ];
    }

    /**
     * Clear all activity logs.
     */
    public function clear(): void {
        delete_option(self::OPTION_LOGS);
    }

    /**
     * Prune logs older than configured retention period (default 30 days).
     */
    public function prune(): void {
        $retentionDays = max(1, min(365, (int) $this->settingsManager->get('log_retention_days', 30)));
        $cutoffTime    = time() - ($retentionDays * DAY_IN_SECONDS);

        $logs = $this->getRawLogs();
        $filtered = array_filter($logs, static function (array $entry) use ($cutoffTime): bool {
            $time = (int) ($entry['time'] ?? 0);
            return $time >= $cutoffTime;
        });

        if (count($filtered) !== count($logs)) {
            update_option(self::OPTION_LOGS, array_values($filtered), false);
        }
    }

    /**
     * Sanitize context array to prevent storing secrets or sensitive data.
     *
     * @param array<string, mixed> $context
     * @return array<string, scalar>
     */
    private function sanitizeContext(array $context): array {
        $sanitized = [];
        $forbiddenKeys = ['license', 'key', 'password', 'token', 'secret', 'authorization', 'cookie'];

        foreach ($context as $key => $value) {
            $lowerKey = strtolower((string) $key);
            foreach ($forbiddenKeys as $forbidden) {
                if (str_contains($lowerKey, $forbidden)) {
                    $sanitized[$key] = '[REDACTED]';
                    continue 2;
                }
            }

            if (is_scalar($value)) {
                $sanitized[$key] = sanitize_text_field((string) $value);
            }
        }

        return $sanitized;
    }

    /**
     * @return array<array<string, mixed>>
     */
    private function getRawLogs(): array {
        $logs = get_option(self::OPTION_LOGS, []);
        return is_array($logs) ? $logs : [];
    }
}
