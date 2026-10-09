<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Updater;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles seamless in-dashboard updates for WPCalibrate RSS Importer via GitHub releases.
 */
final class GitHubUpdater {

    public const GITHUB_REPO = 'zeeshanraza-official/wpcalibrate-rss-importer';
    public const TRANSIENT_KEY = 'wpcri_github_release_data';
    public const CACHE_HOURS = 6;

    private string $currentVersion;
    private string $pluginFile;
    private string $slug;

    public function __construct(string $pluginFile, string $currentVersion) {
        $this->pluginFile     = $pluginFile;
        $this->currentVersion = $currentVersion;
        $this->slug           = 'wpcalibrate-rss-importer';
    }

    public function init(): void {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'checkUpdate']);
        add_filter('plugins_api', [$this, 'pluginInfo'], 20, 3);
        add_filter('upgrader_post_install', [$this, 'postInstall'], 10, 3);
    }

    /**
     * Check GitHub for a new release and inject update response into WordPress update transient.
     */
    public function checkUpdate(mixed $transient): mixed {
        if (!is_object($transient)) {
            $transient = new \stdClass();
        }

        $release = $this->getLatestRelease();
        if ($release === null || empty($release['tag_name'])) {
            return $transient;
        }

        $remoteVersion = ltrim((string) $release['tag_name'], 'v');
        if (version_compare($remoteVersion, $this->currentVersion, '>')) {
            $packageUrl     = $this->getPackageUrl($release);
            $pluginBasename = plugin_basename($this->pluginFile);

            $obj = (object) [
                'id'            => $this->slug,
                'slug'          => $this->slug,
                'plugin'        => $pluginBasename,
                'new_version'   => $remoteVersion,
                'url'           => $release['html_url'] ?? ('https://github.com/' . self::GITHUB_REPO),
                'package'       => $packageUrl,
                'icons'         => [
                    '1x' => WPCRI_PLUGIN_URL . 'branding/icon-dark.png',
                    '2x' => WPCRI_PLUGIN_URL . 'branding/icon-dark.png',
                ],
                'banners'       => [],
                'tested'        => '7.1.3',
                'requires_php'  => '8.2',
                'compatibility' => new \stdClass(),
            ];

            if (!isset($transient->response) || !is_array($transient->response)) {
                $transient->response = [];
            }
            $transient->response[$pluginBasename] = $obj;
        }

        return $transient;
    }

    /**
     * Provide detailed release information when users click "View version details".
     */
    public function pluginInfo(mixed $result, string $action, mixed $args): mixed {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }

        $release = $this->getLatestRelease();
        if ($release === null) {
            return $result;
        }

        $remoteVersion = ltrim((string) ($release['tag_name'] ?? $this->currentVersion), 'v');
        $packageUrl    = $this->getPackageUrl($release);

        return (object) [
            'name'          => 'WPCalibrate RSS Importer',
            'slug'          => $this->slug,
            'version'       => $remoteVersion,
            'author'        => '<a href="https://wpcalibrate.com">WPCalibrate</a>',
            'homepage'      => 'https://wpcalibrate.com/plugins/rss-importer',
            'requires'      => '6.2',
            'tested'        => '7.1.3',
            'requires_php'  => '8.2',
            'download_link' => $packageUrl,
            'sections'      => [
                'description' => esc_html__('Import RSS and Atom feeds into WordPress posts, pages, or custom post types with enterprise filtering, media handling, and scheduling.', 'wpcalibrate-rss-importer'),
                'changelog'   => nl2br(esc_html((string) ($release['body'] ?? ''))),
            ],
            'banners'       => [],
        ];
    }

    /**
     * Ensure correct destination directory upon auto-update extraction.
     */
    public function postInstall(bool $true, array $hookExtra, array $result): array {
        global $wp_filesystem;

        $pluginBasename = plugin_basename($this->pluginFile);
        if (!isset($hookExtra['plugin']) || $hookExtra['plugin'] !== $pluginBasename) {
            return $result;
        }

        $properDestination = WP_PLUGIN_DIR . '/' . $this->slug;
        if (isset($result['destination']) && $result['destination'] !== $properDestination && is_object($wp_filesystem)) {
            $wp_filesystem->move($result['destination'], $properDestination);
            $result['destination'] = $properDestination;
        }

        return $result;
    }

    /**
     * Fetch latest release from GitHub API with transient caching.
     */
    private function getLatestRelease(): ?array {
        $cached = get_site_transient(self::TRANSIENT_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $url = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
        $response = wp_remote_get($url, [
            'timeout'    => 10,
            'user-agent' => 'WPCalibrate-RSS-Importer-Updater/' . $this->currentVersion,
            'headers'    => [
                'Accept' => 'application/vnd.github.v3+json',
            ],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data) || empty($data['tag_name'])) {
            return null;
        }

        set_site_transient(self::TRANSIENT_KEY, $data, self::CACHE_HOURS * HOUR_IN_SECONDS);
        return $data;
    }

    /**
     * Extract package download URL, prioritizing release zip asset over generic zipball.
     */
    private function getPackageUrl(array $release): string {
        if (!empty($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (isset($asset['browser_download_url']) && str_ends_with((string) $asset['name'], '.zip')) {
                    return (string) $asset['browser_download_url'];
                }
            }
        }

        return (string) ($release['zipball_url'] ?? '');
    }
}
