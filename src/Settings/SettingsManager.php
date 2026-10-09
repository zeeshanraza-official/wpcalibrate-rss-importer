<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages plugin global options, validation, and defaults.
 */
final class SettingsManager {

    public const OPTION_NAME = 'wpcri_settings';

    /**
     * Get all plugin settings merged with defaults.
     *
     * @return array<string, mixed>
     */
    public function getAll(): array {
        $saved = get_option(self::OPTION_NAME, []);
        $saved = is_array($saved) ? $saved : [];

        return array_merge(self::getDefaults(), $saved);
    }

    /**
     * Get specific setting value.
     */
    public function get(string $key, mixed $default = null): mixed {
        $settings = $this->getAll();
        return $settings[$key] ?? $default;
    }

    /**
     * Update and sanitize plugin settings.
     *
     * @param array<string, mixed> $input
     */
    public function update(array $input): void {
        $sanitized = $this->sanitize($input);
        update_option(self::OPTION_NAME, $sanitized, false);
    }

    /**
     * Default plugin options.
     *
     * @return array<string, mixed>
     */
    public static function getDefaults(): array {
        return [
            'default_post_status'        => 'draft',
            'default_author_id'          => 1,
            'default_batch_size'         => 10,
            'http_timeout'               => 15,
            'log_retention_days'         => 30,
            'default_import_images'      => true,
            'default_set_featured_image' => true,
            'default_use_canonical'      => false,
            'delete_data_on_uninstall'   => false,
        ];
    }

    /**
     * Initialize defaults on first activation.
     */
    public static function initializeDefaults(): void {
        $current = get_option(self::OPTION_NAME);
        if ($current === false) {
            update_option(self::OPTION_NAME, self::getDefaults(), false);
        }
    }

    /**
     * Sanitize settings array.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function sanitize(array $input): array {
        $defaults = self::getDefaults();
        $sanitized = [];

        $sanitized['default_post_status'] = in_array($input['default_post_status'] ?? '', ['draft', 'pending', 'publish'], true)
            ? (string) $input['default_post_status']
            : $defaults['default_post_status'];

        $sanitized['default_author_id'] = max(1, (int) ($input['default_author_id'] ?? $defaults['default_author_id']));
        $sanitized['default_batch_size'] = max(1, min(100, (int) ($input['default_batch_size'] ?? $defaults['default_batch_size'])));
        $sanitized['http_timeout']       = max(5, min(60, (int) ($input['http_timeout'] ?? $defaults['http_timeout'])));
        $sanitized['log_retention_days'] = max(1, min(365, (int) ($input['log_retention_days'] ?? $defaults['log_retention_days'])));

        $sanitized['default_import_images']      = !empty($input['default_import_images']);
        $sanitized['default_set_featured_image'] = !empty($input['default_set_featured_image']);
        $sanitized['default_use_canonical']      = !empty($input['default_use_canonical']);
        $sanitized['delete_data_on_uninstall']   = !empty($input['delete_data_on_uninstall']);

        return $sanitized;
    }
}
