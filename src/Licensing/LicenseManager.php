<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Licensing;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages license data storage, masking, and integration boundary.
 * Does not fake activations or simulate licensing without an authentic provider.
 */
final class LicenseManager {

    public const OPTION_LICENSE = 'wpcri_license_data';

    private ?LicenseProviderInterface $provider = null;

    public function __construct() {
        /**
         * Filter to register a concrete licensing provider.
         *
         * @param LicenseProviderInterface|null $provider Provider instance.
         */
        $this->provider = apply_filters('wpcri_license_provider', null);
    }

    /**
     * Check if a valid licensing provider is configured.
     */
    public function isProviderConfigured(): bool {
        return $this->provider !== null && $this->provider->isConfigured();
    }

    /**
     * Get the active provider name.
     */
    public function getProviderName(): string {
        return $this->provider ? $this->provider->getProviderName() : __('None (Provider Not Configured)', 'wpcalibrate-rss-importer');
    }

    /**
     * Get stored license state.
     *
     * @return array{key_masked: string, status: string, expires_at: ?string, renewal_url: ?string}
     */
    public function getLicenseState(): array {
        $data = get_option(self::OPTION_LICENSE, []);
        $data = is_array($data) ? $data : [];

        $rawKey    = (string) ($data['key'] ?? '');
        $status    = (string) ($data['status'] ?? 'unconfigured');
        $expiresAt = !empty($data['expires_at']) ? (string) $data['expires_at'] : null;
        $renewalUrl = !empty($data['renewal_url']) ? (string) $data['renewal_url'] : null;

        return [
            'key_masked'  => $this->maskKey($rawKey),
            'status'      => $this->isProviderConfigured() ? $status : 'unconfigured',
            'expires_at'  => $expiresAt,
            'renewal_url' => $renewalUrl,
        ];
    }

    /**
     * Save license key without exposing or faking activation.
     */
    public function saveKey(string $key): void {
        $cleanKey = sanitize_text_field(trim($key));
        $data = get_option(self::OPTION_LICENSE, []);
        $data = is_array($data) ? $data : [];

        $data['key'] = $cleanKey;
        if (!$this->isProviderConfigured()) {
            $data['status'] = 'unconfigured';
        }

        update_option(self::OPTION_LICENSE, $data, false);
    }

    /**
     * Remove license data.
     */
    public function removeLicense(): void {
        delete_option(self::OPTION_LICENSE);
    }

    /**
     * Mask a license key showing only first 4 and last 4 characters.
     */
    public function maskKey(string $key): string {
        $key = trim($key);
        $len = strlen($key);
        if ($len <= 4) {
            return str_repeat('•', $len);
        }

        if ($len <= 8) {
            return substr($key, 0, 2) . str_repeat('•', $len - 4) . substr($key, -2);
        }

        return substr($key, 0, 4) . '-' . str_repeat('•', max(4, $len - 8)) . '-' . substr($key, -4);
    }
}
