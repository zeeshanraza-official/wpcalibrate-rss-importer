<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Licensing;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Contract for future WPCalibrate Pro licensing providers.
 */
interface LicenseProviderInterface {

    /**
     * Whether provider endpoints and credentials are configured.
     */
    public function isConfigured(): bool;

    /**
     * Unique provider name/identifier.
     */
    public function getProviderName(): string;

    /**
     * Activate a license key.
     *
     * @return array{status: string, expires_at: ?string, renewal_url: ?string}|\WP_Error
     */
    public function activate(string $licenseKey): array|\WP_Error;

    /**
     * Deactivate an active license key.
     *
     * @return bool|\WP_Error
     */
    public function deactivate(string $licenseKey): bool|\WP_Error;

    /**
     * Validate status and expiration.
     *
     * @return array{status: string, expires_at: ?string, renewal_url: ?string}|\WP_Error
     */
    public function checkStatus(string $licenseKey): array|\WP_Error;
}
