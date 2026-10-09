<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Admin;

use WPCalibrate\RSSImporter\Feed\FeedRepository;
use WPCalibrate\RSSImporter\Feed\FeedSource;
use WPCalibrate\RSSImporter\Import\ImportManager;
use WPCalibrate\RSSImporter\Settings\SettingsManager;
use WPCalibrate\RSSImporter\Logging\Logger;
use WPCalibrate\RSSImporter\Licensing\LicenseManager;
use WPCalibrate\RSSImporter\Scheduling\ScheduleManager;
use WPCalibrate\RSSImporter\Compatibility\PostTypeHelper;
use WPCalibrate\RSSImporter\Support\SupportManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Controller for RSS Importer admin screens, tabs, and action handlers.
 */
final class AdminController {

    private FeedRepository $feedRepository;
    private ImportManager $importManager;
    private SettingsManager $settingsManager;
    private Logger $logger;
    private LicenseManager $licenseManager;
    private ScheduleManager $scheduleManager;

    /** @var array<array{type: string, message: string}> */
    private array $notices = [];

    public function __construct(
        FeedRepository $feedRepository,
        ImportManager $importManager,
        SettingsManager $settingsManager,
        Logger $logger,
        LicenseManager $licenseManager,
        ScheduleManager $scheduleManager
    ) {
        $this->feedRepository  = $feedRepository;
        $this->importManager   = $importManager;
        $this->settingsManager = $settingsManager;
        $this->logger          = $logger;
        $this->licenseManager  = $licenseManager;
        $this->scheduleManager = $scheduleManager;
    }

    public function init(): void {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_init', [$this, 'handlePostActions']);
    }

    /**
     * Enqueue assets strictly on plugin admin screens.
     */
    public function enqueueAssets(string $hook): void {
        if (!str_contains($hook, 'wpcalibrate-rss-importer')) {
            return;
        }

        wp_enqueue_style(
            'wpcri-admin-css',
            WPCRI_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WPCRI_VERSION
        );

        wp_enqueue_script(
            'wpcri-admin-js',
            WPCRI_PLUGIN_URL . 'assets/js/admin.js',
            [],
            WPCRI_VERSION,
            true
        );
    }

    /**
     * Handle state-changing admin actions with capability and nonce verification.
     */
    public function handlePostActions(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $action = sanitize_key($_POST['wpcri_action'] ?? $_GET['wpcri_action'] ?? '');
        if (empty($action)) {
            return;
        }

        // 1. Save Feed Source
        if ($action === 'save_feed' && check_admin_referer('wpcri_save_feed_nonce')) {
            $this->handleSaveFeed();
            return;
        }

        // 2. Delete Feed Source
        if ($action === 'delete_feed' && check_admin_referer('wpcri_delete_feed_nonce')) {
            $this->handleDeleteFeed();
            return;
        }

        // 3. Toggle Pause / Enable
        if ($action === 'toggle_feed' && check_admin_referer('wpcri_toggle_feed_nonce')) {
            $this->handleToggleFeed();
            return;
        }

        // 4. Manual Import Now
        if ($action === 'import_now' && check_admin_referer('wpcri_import_feed_nonce')) {
            $this->handleImportNow();
            return;
        }

        // 5. Save Global Settings
        if ($action === 'save_settings' && check_admin_referer('wpcri_save_settings_nonce')) {
            $this->handleSaveSettings();
            return;
        }

        // 6. Clear Logs
        if ($action === 'clear_logs' && check_admin_referer('wpcri_clear_logs_nonce')) {
            $this->logger->clear();
            $this->addNotice('success', __('Activity logs cleared successfully.', 'wpcalibrate-rss-importer'));
            return;
        }

        // 7. Save License Key
        if ($action === 'save_license' && check_admin_referer('wpcri_save_license_nonce')) {
            $key = sanitize_text_field((string) ($_POST['license_key'] ?? ''));
            $this->licenseManager->saveKey($key);
            $this->addNotice('info', __('License key saved. Note: License provider is not configured in this environment.', 'wpcalibrate-rss-importer'));
            return;
        }
    }

    /**
     * Render the main admin page with tab navigation.
     */
    public function renderPage(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html(__('You do not have permission to access this page.', 'wpcalibrate-rss-importer')));
        }

        $currentTab = sanitize_key($_GET['tab'] ?? 'overview');
        $validTabs  = ['overview', 'feeds', 'add', 'settings', 'logs', 'license', 'support'];
        if (!in_array($currentTab, $validTabs, true)) {
            $currentTab = 'overview';
        }

        $brandingUrl = WPCRI_PLUGIN_URL . 'branding/icon-dark.png';
        ?>
        <div id="wpcri-wrap" class="wrap">
            <header class="wpcri-header">
                <div class="wpcri-branding">
                    <img src="<?php echo esc_url($brandingUrl); ?>" alt="<?php esc_attr_e('WPCalibrate', 'wpcalibrate-rss-importer'); ?>" class="wpcri-brand-logo" width="44" height="44" />
                    <div>
                        <h1 class="wpcri-header-title">
                            <?php esc_html_e('WPCalibrate RSS Importer', 'wpcalibrate-rss-importer'); ?>
                            <span class="wpcri-version-badge"><?php echo esc_html('v' . WPCRI_VERSION); ?></span>
                        </h1>
                    </div>
                </div>
                <div class="wpcri-header-links">
                    <a href="<?php echo esc_url(SupportManager::WEBSITE_URL); ?>" target="_blank" rel="noopener noreferrer" class="button">
                        <?php esc_html_e('Website', 'wpcalibrate-rss-importer'); ?>
                    </a>
                    <a href="<?php echo esc_url(SupportManager::MARKETPLACE); ?>" target="_blank" rel="noopener noreferrer" class="button">
                        <?php esc_html_e('Marketplace', 'wpcalibrate-rss-importer'); ?>
                    </a>
                </div>
            </header>

            <?php $this->renderNotices(); ?>

            <nav class="nav-tab-wrapper wpcri-nav-tabs" aria-label="<?php esc_attr_e('RSS Importer Navigation', 'wpcalibrate-rss-importer'); ?>">
                <a href="<?php echo esc_url(admin_url('admin.php?page=wpcalibrate-rss-importer&tab=overview')); ?>" class="nav-tab <?php echo $currentTab === 'overview' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Overview', 'wpcalibrate-rss-importer'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wpcalibrate-rss-importer&tab=feeds')); ?>" class="nav-tab <?php echo $currentTab === 'feeds' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Feeds', 'wpcalibrate-rss-importer'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wpcalibrate-rss-importer&tab=add')); ?>" class="nav-tab <?php echo $currentTab === 'add' ? 'nav-tab-active' : ''; ?>">
                    <?php echo isset($_GET['feed_id']) ? esc_html__('Edit Feed', 'wpcalibrate-rss-importer') : esc_html__('Add Feed', 'wpcalibrate-rss-importer'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wpcalibrate-rss-importer&tab=settings')); ?>" class="nav-tab <?php echo $currentTab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Settings', 'wpcalibrate-rss-importer'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wpcalibrate-rss-importer&tab=logs')); ?>" class="nav-tab <?php echo $currentTab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Logs & Diagnostics', 'wpcalibrate-rss-importer'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wpcalibrate-rss-importer&tab=license')); ?>" class="nav-tab <?php echo $currentTab === 'license' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('License', 'wpcalibrate-rss-importer'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wpcalibrate-rss-importer&tab=support')); ?>" class="nav-tab <?php echo $currentTab === 'support' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Support', 'wpcalibrate-rss-importer'); ?>
                </a>
            </nav>

            <main class="wpcri-content">
                <?php
                match ($currentTab) {
                    'overview' => $this->renderOverviewTab(),
                    'feeds'    => $this->renderFeedsTab(),
                    'add'      => $this->renderAddEditTab(),
                    'settings' => $this->renderSettingsTab(),
                    'logs'     => $this->renderLogsTab(),
                    'license'  => $this->renderLicenseTab(),
                    'support'  => $this->renderSupportTab(),
                    default    => $this->renderOverviewTab(),
                };
                ?>
            </main>
        </div>
        <?php
    }

    /**
     * Render the Plugin Overview tab (Purpose, How to Use, Method, and Quick Stats).
     */
    private function renderOverviewTab(): void {
        $totalFeeds   = $this->feedRepository->count();
        $allFeeds     = $this->feedRepository->getAll(['posts_per_page' => -1]);
        $activeFeeds  = 0;
        $totalImportedArticles = 0;

        foreach ($allFeeds as $f) {
            if ($f->isEnabled()) {
                $activeFeeds++;
            }
            $totalImportedArticles += $f->getTotalImported();
        }

        $nextCron = wp_next_scheduled(ScheduleManager::CRON_HOOK_DISPATCHER);
        $cronStatus = $nextCron ? sprintf(__('Active (Next in %d mins)', 'wpcalibrate-rss-importer'), max(1, (int) round(($nextCron - time()) / 60))) : __('Standby', 'wpcalibrate-rss-importer');
        ?>
        <div class="wpcri-overview-wrapper">
            <!-- Hero / Welcome Banner -->
            <div class="wpcri-card wpcri-hero-card">
                <div class="wpcri-hero-content">
                    <span class="wpcri-badge wpcri-badge-info"><?php esc_html_e('Official Documentation & Guide', 'wpcalibrate-rss-importer'); ?></span>
                    <h2 class="wpcri-hero-title"><?php esc_html_e('Welcome to WPCalibrate RSS Importer', 'wpcalibrate-rss-importer'); ?></h2>
                    <p class="wpcri-hero-desc">
                        <?php esc_html_e('A production-ready content ingestion engine for WordPress. Ingest content from unlimited RSS 2.0 and Atom feeds into posts, pages, or custom post types with enterprise-grade deduplication, filtering, and automated scheduling.', 'wpcalibrate-rss-importer'); ?>
                    </p>
                    <div class="wpcri-hero-actions">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wpcalibrate-rss-importer&tab=add')); ?>" class="button button-primary button-hero">
                            <?php esc_html_e('Add New Feed Source', 'wpcalibrate-rss-importer'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wpcalibrate-rss-importer&tab=feeds')); ?>" class="button button-secondary button-hero">
                            <?php esc_html_e('Manage Configured Feeds', 'wpcalibrate-rss-importer'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wpcalibrate-rss-importer&tab=settings')); ?>" class="button button-secondary button-hero">
                            <?php esc_html_e('Plugin Settings', 'wpcalibrate-rss-importer'); ?>
                        </a>
                    </div>
                </div>
            </div>

            <!-- KPI & Health Cards -->
            <div class="wpcri-kpi-grid">
                <div class="wpcri-card wpcri-kpi-card">
                    <div class="wpcri-kpi-label"><?php esc_html_e('Total Feeds Configured', 'wpcalibrate-rss-importer'); ?></div>
                    <div class="wpcri-kpi-value"><?php echo esc_html((string) $totalFeeds); ?></div>
                    <div class="wpcri-kpi-hint"><?php esc_html_e('Unlimited sources supported', 'wpcalibrate-rss-importer'); ?></div>
                </div>
                <div class="wpcri-card wpcri-kpi-card">
                    <div class="wpcri-kpi-label"><?php esc_html_e('Active Automated Feeds', 'wpcalibrate-rss-importer'); ?></div>
                    <div class="wpcri-kpi-value"><?php echo esc_html((string) $activeFeeds); ?></div>
                    <div class="wpcri-kpi-hint"><?php esc_html_e('Scheduled via WP-Cron', 'wpcalibrate-rss-importer'); ?></div>
                </div>
                <div class="wpcri-card wpcri-kpi-card">
                    <div class="wpcri-kpi-label"><?php esc_html_e('Articles Ingested', 'wpcalibrate-rss-importer'); ?></div>
                    <div class="wpcri-kpi-value"><?php echo esc_html((string) $totalImportedArticles); ?></div>
                    <div class="wpcri-kpi-hint"><?php esc_html_e('Total successful post imports', 'wpcalibrate-rss-importer'); ?></div>
                </div>
                <div class="wpcri-card wpcri-kpi-card">
                    <div class="wpcri-kpi-label"><?php esc_html_e('Cron Dispatcher Health', 'wpcalibrate-rss-importer'); ?></div>
                    <div class="wpcri-kpi-value wpcri-kpi-status"><?php echo esc_html($cronStatus); ?></div>
                    <div class="wpcri-kpi-hint"><?php esc_html_e('15-min recurring dispatcher', 'wpcalibrate-rss-importer'); ?></div>
                </div>
            </div>

            <!-- Section 1: Plugin Purpose -->
            <div class="wpcri-card">
                <h2><?php esc_html_e('Plugin Purpose & Core Philosophy', 'wpcalibrate-rss-importer'); ?></h2>
                <div class="wpcri-purpose-grid">
                    <div class="wpcri-purpose-item">
                        <h3><?php esc_html_e('Unlimited Feed Ingestion', 'wpcalibrate-rss-importer'); ?></h3>
                        <p><?php esc_html_e('Unlike restrictive tools, WPCalibrate RSS Importer allows you to add, manage, and schedule unlimited RSS and Atom feed sources without artificial limits or crippled core capabilities.', 'wpcalibrate-rss-importer'); ?></p>
                    </div>
                    <div class="wpcri-purpose-item">
                        <h3><?php esc_html_e('Native WordPress Storage', 'wpcalibrate-rss-importer'); ?></h3>
                        <p><?php esc_html_e('Feed source configurations are stored as private WordPress post types and post meta. No unnecessary custom tables, no lock-in, and deleting a feed source never removes previously imported articles.', 'wpcalibrate-rss-importer'); ?></p>
                    </div>
                    <div class="wpcri-purpose-item">
                        <h3><?php esc_html_e('Production Reliability', 'wpcalibrate-rss-importer'); ?></h3>
                        <p><?php esc_html_e('Engineered with SSRF protection, XML entity injection safeguards, memory-friendly batch processing, concurrency locking, and idempotent post updates.', 'wpcalibrate-rss-importer'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Section 2: How to Use (Visual 5-Step Workflow) -->
            <div class="wpcri-card">
                <h2><?php esc_html_e('How to Use WPCalibrate RSS Importer', 'wpcalibrate-rss-importer'); ?></h2>
                <p class="wpcri-help-text"><?php esc_html_e('Follow these 5 simple steps to set up and automate your feed ingestion workflow:', 'wpcalibrate-rss-importer'); ?></p>

                <div class="wpcri-steps-timeline">
                    <!-- Step 1 -->
                    <div class="wpcri-step-card">
                        <div class="wpcri-step-number">1</div>
                        <div class="wpcri-step-body">
                            <h3><?php esc_html_e('Add & Test Your Feed Source', 'wpcalibrate-rss-importer'); ?></h3>
                            <p><?php esc_html_e('Go to the Add Feed tab. Enter a descriptive feed name and the remote RSS 2.0 or Atom URL. Use the built-in Test Feed button to verify server reachability and inspect available items immediately.', 'wpcalibrate-rss-importer'); ?></p>
                        </div>
                    </div>

                    <!-- Step 2 -->
                    <div class="wpcri-step-card">
                        <div class="wpcri-step-number">2</div>
                        <div class="wpcri-step-body">
                            <h3><?php esc_html_e('Choose Destination Post Type & Author', 'wpcalibrate-rss-importer'); ?></h3>
                            <p><?php esc_html_e('Select whether imported items become Posts, Pages, or compatible public Custom Post Types. Set the initial post status (Draft, Pending Review, or Published) and assign the destination author.', 'wpcalibrate-rss-importer'); ?></p>
                        </div>
                    </div>

                    <!-- Step 3 -->
                    <div class="wpcri-step-card">
                        <div class="wpcri-step-number">3</div>
                        <div class="wpcri-step-body">
                            <h3><?php esc_html_e('Configure Filtering & Taxonomies', 'wpcalibrate-rss-importer'); ?></h3>
                            <p><?php esc_html_e('Set keyword include/exclude rules to import only qualifying stories. Map feed categories to WordPress taxonomy terms with optional automatic term creation, and set maximum article age limits.', 'wpcalibrate-rss-importer'); ?></p>
                        </div>
                    </div>

                    <!-- Step 4 -->
                    <div class="wpcri-step-card">
                        <div class="wpcri-step-number">4</div>
                        <div class="wpcri-step-body">
                            <h3><?php esc_html_e('Featured Images & Source Attribution', 'wpcalibrate-rss-importer'); ?></h3>
                            <p><?php esc_html_e('Enable automated Featured Image sideloading to discover images from Media RSS, enclosures, or content. Optionally append clean source attribution links and preserve canonical URL references.', 'wpcalibrate-rss-importer'); ?></p>
                        </div>
                    </div>

                    <!-- Step 5 -->
                    <div class="wpcri-step-card">
                        <div class="wpcri-step-number">5</div>
                        <div class="wpcri-step-body">
                            <h3><?php esc_html_e('Schedule Ingestion & Monitor Diagnostics', 'wpcalibrate-rss-importer'); ?></h3>
                            <p><?php esc_html_e('Choose automated schedules (every 15m, 30m, hourly, twice daily, or daily) or trigger manual Import Now batches at any time. Monitor import operations and review detailed logs in the Logs & Diagnostics tab.', 'wpcalibrate-rss-importer'); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 3: Technical Method & Ingestion Architecture -->
            <div class="wpcri-card">
                <h2><?php esc_html_e('Technical Method & Architecture (Under the Hood)', 'wpcalibrate-rss-importer'); ?></h2>
                <div class="wpcri-method-grid">
                    <div class="wpcri-method-box">
                        <div class="wpcri-method-header">
                            <span class="dashicons dashicons-shield"></span>
                            <h4><?php esc_html_e('SSRF Protection & Safe Transport', 'wpcalibrate-rss-importer'); ?></h4>
                        </div>
                        <p><?php esc_html_e('Remote URLs are validated before requests. Requests to localhost, private IP subnets (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16), IPv6 loopback, and cloud metadata endpoints (169.254.169.254) are strictly blocked.', 'wpcalibrate-rss-importer'); ?></p>
                    </div>

                    <div class="wpcri-method-box">
                        <div class="wpcri-method-header">
                            <span class="dashicons dashicons-code-standards"></span>
                            <h4><?php esc_html_e('Robust XML Parsing & XXE Mitigation', 'wpcalibrate-rss-importer'); ?></h4>
                        </div>
                        <p><?php esc_html_e('Feeds are parsed using SimpleXML configured with LIBXML_NONET and LIBXML_NOCDATA, preventing XML external entity attacks while extracting RSS 2.0, RSS 1.0, Atom, and Media RSS structures cleanly.', 'wpcalibrate-rss-importer'); ?></p>
                    </div>

                    <div class="wpcri-method-box">
                        <div class="wpcri-method-header">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <h4><?php esc_html_e('Multi-Tiered Duplicate Prevention', 'wpcalibrate-rss-importer'); ?></h4>
                        </div>
                        <p><?php esc_html_e('Each feed item receives a durable fingerprint combining feed source ID, GUID, and normalized URL (marketing tracking parameters stripped). Fallback content hashing prevents duplicates even when feeds lack GUIDs.', 'wpcalibrate-rss-importer'); ?></p>
                    </div>

                    <div class="wpcri-method-box">
                        <div class="wpcri-method-header">
                            <span class="dashicons dashicons-lock"></span>
                            <h4><?php esc_html_e('Concurrency Locking & Stale Lock Recovery', 'wpcalibrate-rss-importer'); ?></h4>
                        </div>
                        <p><?php esc_html_e('Every import run acquires a unique process token with time-to-live expiration. Overlapping cron and manual executions are safely prevented, and expired locks automatically recover without administrator intervention.', 'wpcalibrate-rss-importer'); ?></p>
                    </div>

                    <div class="wpcri-method-box">
                        <div class="wpcri-method-header">
                            <span class="dashicons dashicons-format-image"></span>
                            <h4><?php esc_html_e('Safe Media Sideloading & Deduplication', 'wpcalibrate-rss-importer'); ?></h4>
                        </div>
                        <p><?php esc_html_e('Discovered images are checked for safe MIME types and file size boundaries. Image URLs are deduplicated across runs to avoid storing redundant attachments in your WordPress Media Library.', 'wpcalibrate-rss-importer'); ?></p>
                    </div>

                    <div class="wpcri-method-box">
                        <div class="wpcri-method-header">
                            <span class="dashicons dashicons-update"></span>
                            <h4><?php esc_html_e('Non-Destructive Post Updates', 'wpcalibrate-rss-importer'); ?></h4>
                        </div>
                        <p><?php esc_html_e('When update mode is enabled, only importer-managed fields are updated upon feed changes. Manual edits, custom post meta, and user customizations are strictly preserved.', 'wpcalibrate-rss-importer'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render Feeds list tab.
     */
    private function renderFeedsTab(): void {
        $paged   = max(1, (int) ($_GET['paged'] ?? 1));
        $search  = sanitize_text_field((string) ($_GET['s'] ?? ''));
        $feeds   = $this->feedRepository->getAll(['paged' => $paged, 'per_page' => 20, 's' => $search]);
        $total   = $this->feedRepository->count(['s' => $search]);
        ?>
        <div class="wpcri-card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                <h2 style="margin:0; border:none; padding:0;"><?php esc_html_e('Configured Feed Sources', 'wpcalibrate-rss-importer'); ?></h2>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wpcalibrate-rss-importer&tab=add')); ?>" class="button button-primary">
                    <?php esc_html_e('Add New Feed', 'wpcalibrate-rss-importer'); ?>
                </a>
            </div>

            <?php if (empty($feeds)) : ?>
                <p><?php esc_html_e('No feed sources found. Click "Add New Feed" to create your first RSS/Atom import source.', 'wpcalibrate-rss-importer'); ?></p>
            <?php else : ?>
                <table class="wpcri-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Feed Name', 'wpcalibrate-rss-importer'); ?></th>
                            <th><?php esc_html_e('Destination', 'wpcalibrate-rss-importer'); ?></th>
                            <th><?php esc_html_e('Schedule', 'wpcalibrate-rss-importer'); ?></th>
                            <th><?php esc_html_e('Status', 'wpcalibrate-rss-importer'); ?></th>
                            <th><?php esc_html_e('Stats (Imp / Skip / Fail)', 'wpcalibrate-rss-importer'); ?></th>
                            <th><?php esc_html_e('Last Run', 'wpcalibrate-rss-importer'); ?></th>
                            <th><?php esc_html_e('Actions', 'wpcalibrate-rss-importer'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($feeds as $feed) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($feed->getName()); ?></strong><br />
                                    <small><a href="<?php echo esc_url($feed->getUrl()); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($feed->getUrl()); ?></a></small>
                                </td>
                                <td>
                                    <?php echo esc_html(ucfirst($feed->getDestinationPostType())); ?>
                                    <span class="wpcri-badge wpcri-badge-info"><?php echo esc_html(ucfirst($feed->getDestinationStatus())); ?></span>
                                </td>
                                <td>
                                    <?php echo esc_html(ucfirst($feed->getSchedule())); ?>
                                    <small>(<?php echo esc_html(sprintf(__('Batch: %d', 'wpcalibrate-rss-importer'), $feed->getBatchSize())); ?>)</small>
                                </td>
                                <td>
                                    <?php if ($feed->isEnabled()) : ?>
                                        <span class="wpcri-badge wpcri-badge-enabled"><?php esc_html_e('Active', 'wpcalibrate-rss-importer'); ?></span>
                                    <?php else : ?>
                                        <span class="wpcri-badge wpcri-badge-disabled"><?php esc_html_e('Paused', 'wpcalibrate-rss-importer'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo esc_html((string) $feed->getTotalImported()); ?></strong> /
                                    <?php echo esc_html((string) $feed->getTotalSkipped()); ?> /
                                    <span style="color:<?php echo $feed->getTotalFailed() > 0 ? '#d63638' : 'inherit'; ?>"><?php echo esc_html((string) $feed->getTotalFailed()); ?></span>
                                </td>
                                <td>
                                    <?php echo esc_html($feed->getLastAttemptedRun() ?: __('Never', 'wpcalibrate-rss-importer')); ?>
                                </td>
                                <td>
                                    <div class="wpcri-row-actions">
                                        <!-- Import Now -->
                                        <form method="post" action="">
                                            <?php wp_nonce_field('wpcri_import_feed_nonce'); ?>
                                            <input type="hidden" name="wpcri_action" value="import_now" />
                                            <input type="hidden" name="feed_id" value="<?php echo esc_attr((string) $feed->getId()); ?>" />
                                            <button type="submit" class="button button-small button-primary">
                                                <?php esc_html_e('Import Now', 'wpcalibrate-rss-importer'); ?>
                                            </button>
                                        </form>

                                        <!-- Preview -->
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=wpcalibrate-rss-importer&tab=add&feed_id=' . $feed->getId() . '&action=preview')); ?>" class="button button-small">
                                            <?php esc_html_e('Preview', 'wpcalibrate-rss-importer'); ?>
                                        </a>

                                        <!-- Edit -->
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=wpcalibrate-rss-importer&tab=add&feed_id=' . $feed->getId())); ?>" class="button button-small">
                                            <?php esc_html_e('Edit', 'wpcalibrate-rss-importer'); ?>
                                        </a>

                                        <!-- Toggle Status -->
                                        <form method="post" action="">
                                            <?php wp_nonce_field('wpcri_toggle_feed_nonce'); ?>
                                            <input type="hidden" name="wpcri_action" value="toggle_feed" />
                                            <input type="hidden" name="feed_id" value="<?php echo esc_attr((string) $feed->getId()); ?>" />
                                            <button type="submit" class="button button-small">
                                                <?php echo $feed->isEnabled() ? esc_html__('Pause', 'wpcalibrate-rss-importer') : esc_html__('Enable', 'wpcalibrate-rss-importer'); ?>
                                            </button>
                                        </form>

                                        <!-- Delete -->
                                        <form method="post" action="">
                                            <?php wp_nonce_field('wpcri_delete_feed_nonce'); ?>
                                            <input type="hidden" name="wpcri_action" value="delete_feed" />
                                            <input type="hidden" name="feed_id" value="<?php echo esc_attr((string) $feed->getId()); ?>" />
                                            <button type="submit" class="button button-small button-link-delete wpcri-confirm-action" data-confirm="<?php esc_attr_e('Are you sure you want to delete this feed source? Previously imported posts will be preserved.', 'wpcalibrate-rss-importer'); ?>">
                                                <?php esc_html_e('Delete', 'wpcalibrate-rss-importer'); ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render Add / Edit Feed Form tab.
     */
    private function renderAddEditTab(): void {
        $feedId = (int) ($_GET['feed_id'] ?? 0);
        $feed   = $feedId > 0 ? $this->feedRepository->get($feedId) : null;
        $isEdit = $feed instanceof FeedSource;

        $compatibleTypes = PostTypeHelper::getCompatiblePostTypes();
        $authors = get_users(['capability' => 'edit_posts', 'fields' => ['ID', 'display_name']]);

        // Check if preview requested
        $previewData = null;
        if ($isEdit && isset($_GET['action']) && $_GET['action'] === 'preview') {
            $previewData = $this->importManager->previewFeed($feed, 5);
        }

        // Test feed action
        $testResult = null;
        if (isset($_POST['wpcri_test_url']) && check_admin_referer('wpcri_test_feed_nonce')) {
            $testUrl = esc_url_raw(trim((string) $_POST['wpcri_test_url']));
            $testResult = $this->importManager->testFeed($testUrl);
        }
        ?>
        <div class="wpcri-card">
            <h2><?php echo $isEdit ? esc_html__('Edit Feed Source', 'wpcalibrate-rss-importer') : esc_html__('Add Feed Source', 'wpcalibrate-rss-importer'); ?></h2>

            <!-- Quick Test Feed Box -->
            <div style="background:#f6f7f7; padding:16px; border-radius:6px; margin-bottom:24px;">
                <h3 style="margin-top:0; border:none; padding:0;"><?php esc_html_e('Test Feed URL', 'wpcalibrate-rss-importer'); ?></h3>
                <form method="post" action="">
                    <?php wp_nonce_field('wpcri_test_feed_nonce'); ?>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <input type="url" name="wpcri_test_url" value="<?php echo esc_attr($feed ? $feed->getUrl() : ''); ?>" placeholder="https://example.com/feed" class="regular-text" required style="flex:1;" />
                        <button type="submit" class="button"><?php esc_html_e('Test Feed', 'wpcalibrate-rss-importer'); ?></button>
                    </div>
                </form>
                <?php if ($testResult !== null) : ?>
                    <div style="margin-top:12px;">
                        <?php if (is_wp_error($testResult)) : ?>
                            <div class="notice notice-error inline"><p><?php echo esc_html($testResult->get_error_message()); ?></p></div>
                        <?php else : ?>
                            <div class="notice notice-success inline">
                                <p>
                                    <strong><?php esc_html_e('Feed Test Successful!', 'wpcalibrate-rss-importer'); ?></strong><br />
                                    <?php echo esc_html(sprintf(__('Title: %s | Items Found: %d', 'wpcalibrate-rss-importer'), $testResult['title'], $testResult['count'])); ?>
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Feed Preview Display -->
            <?php if ($previewData !== null) : ?>
                <div class="wpcri-preview-box">
                    <h3><?php esc_html_e('Recent Items Preview', 'wpcalibrate-rss-importer'); ?></h3>
                    <?php if (is_wp_error($previewData)) : ?>
                        <div class="notice notice-error inline"><p><?php echo esc_html($previewData->get_error_message()); ?></p></div>
                    <?php elseif (empty($previewData)) : ?>
                        <p><?php esc_html_e('No items found in feed.', 'wpcalibrate-rss-importer'); ?></p>
                    <?php else : ?>
                        <?php foreach ($previewData as $row) : ?>
                            <?php $pItem = $row['item']; ?>
                            <div class="wpcri-preview-item">
                                <strong><a href="<?php echo esc_url($pItem->getPermalink()); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($pItem->getTitle()); ?></a></strong>
                                <div class="wpcri-preview-meta">
                                    <span><?php echo esc_html(sprintf(__('Date: %s', 'wpcalibrate-rss-importer'), $pItem->getDate() ?: __('N/A', 'wpcalibrate-rss-importer'))); ?></span>
                                    <span>
                                        <?php if ($row['is_duplicate']) : ?>
                                            <span class="wpcri-badge wpcri-badge-warning"><?php echo esc_html(sprintf(__('Duplicate (Post #%d)', 'wpcalibrate-rss-importer'), $row['existing_id'])); ?></span>
                                        <?php else : ?>
                                            <span class="wpcri-badge wpcri-badge-info"><?php esc_html_e('New Item', 'wpcalibrate-rss-importer'); ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <span>
                                        <?php if ($row['filter_allowed']) : ?>
                                            <span class="wpcri-badge wpcri-badge-enabled"><?php esc_html_e('Filter: Allowed', 'wpcalibrate-rss-importer'); ?></span>
                                        <?php else : ?>
                                            <span class="wpcri-badge wpcri-badge-disabled"><?php echo esc_html(sprintf(__('Filter: Excluded (%s)', 'wpcalibrate-rss-importer'), $row['filter_reason'])); ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <span><?php echo $row['has_image'] ? esc_html__('Image: Detected', 'wpcalibrate-rss-importer') : esc_html__('Image: None', 'wpcalibrate-rss-importer'); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Main Feed Configuration Form -->
            <form method="post" action="">
                <?php wp_nonce_field('wpcri_save_feed_nonce'); ?>
                <input type="hidden" name="wpcri_action" value="save_feed" />
                <input type="hidden" name="feed_id" value="<?php echo esc_attr((string) ($feed ? $feed->getId() : 0)); ?>" />

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="feed_name"><?php esc_html_e('Feed Name', 'wpcalibrate-rss-importer'); ?></label></th>
                        <td>
                            <input name="feed_name" type="text" id="feed_name" value="<?php echo esc_attr($feed ? $feed->getName() : ''); ?>" class="regular-text" required />
                            <p class="wpcri-help-text"><?php esc_html_e('A descriptive name for internal identification.', 'wpcalibrate-rss-importer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="feed_url"><?php esc_html_e('Feed URL', 'wpcalibrate-rss-importer'); ?></label></th>
                        <td>
                            <input name="feed_url" type="url" id="feed_url" value="<?php echo esc_attr($feed ? $feed->getUrl() : ''); ?>" class="large-text" required />
                            <p class="wpcri-help-text"><?php esc_html_e('Direct URL to the RSS or Atom feed (HTTP or HTTPS only).', 'wpcalibrate-rss-importer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Status', 'wpcalibrate-rss-importer'); ?></th>
                        <td>
                            <label>
                                <input name="enabled" type="checkbox" value="1" <?php checked($feed ? $feed->isEnabled() : true); ?> />
                                <?php esc_html_e('Enable this feed source for automated imports', 'wpcalibrate-rss-importer'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="destination_post_type"><?php esc_html_e('Destination Post Type', 'wpcalibrate-rss-importer'); ?></label></th>
                        <td>
                            <select name="destination_post_type" id="destination_post_type">
                                <?php foreach ($compatibleTypes as $cSlug => $cLabel) : ?>
                                    <option value="<?php echo esc_attr($cSlug); ?>" <?php selected($feed ? $feed->getDestinationPostType() : 'post', $cSlug); ?>>
                                        <?php echo esc_html($cLabel); ?> (<?php echo esc_html($cSlug); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="destination_status"><?php esc_html_e('Post Status', 'wpcalibrate-rss-importer'); ?></label></th>
                        <td>
                            <select name="destination_status" id="destination_status">
                                <option value="draft" <?php selected($feed ? $feed->getDestinationStatus() : 'draft', 'draft'); ?>><?php esc_html_e('Draft', 'wpcalibrate-rss-importer'); ?></option>
                                <option value="pending" <?php selected($feed ? $feed->getDestinationStatus() : 'draft', 'pending'); ?>><?php esc_html_e('Pending Review', 'wpcalibrate-rss-importer'); ?></option>
                                <option value="publish" <?php selected($feed ? $feed->getDestinationStatus() : 'draft', 'publish'); ?>><?php esc_html_e('Publish', 'wpcalibrate-rss-importer'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="author_id"><?php esc_html_e('Post Author', 'wpcalibrate-rss-importer'); ?></label></th>
                        <td>
                            <select name="author_id" id="author_id">
                                <?php foreach ($authors as $author) : ?>
                                    <option value="<?php echo esc_attr((string) $author->ID); ?>" <?php selected($feed ? $feed->getAuthorId() : get_current_user_id(), $author->ID); ?>>
                                        <?php echo esc_html($author->display_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="schedule"><?php esc_html_e('Schedule', 'wpcalibrate-rss-importer'); ?></label></th>
                        <td>
                            <select name="schedule" id="schedule">
                                <option value="15min" <?php selected($feed ? $feed->getSchedule() : 'hourly', '15min'); ?>><?php esc_html_e('Every 15 Minutes', 'wpcalibrate-rss-importer'); ?></option>
                                <option value="30min" <?php selected($feed ? $feed->getSchedule() : 'hourly', '30min'); ?>><?php esc_html_e('Every 30 Minutes', 'wpcalibrate-rss-importer'); ?></option>
                                <option value="hourly" <?php selected($feed ? $feed->getSchedule() : 'hourly', 'hourly'); ?>><?php esc_html_e('Hourly', 'wpcalibrate-rss-importer'); ?></option>
                                <option value="twicedaily" <?php selected($feed ? $feed->getSchedule() : 'hourly', 'twicedaily'); ?>><?php esc_html_e('Twice Daily', 'wpcalibrate-rss-importer'); ?></option>
                                <option value="daily" <?php selected($feed ? $feed->getSchedule() : 'hourly', 'daily'); ?>><?php esc_html_e('Daily', 'wpcalibrate-rss-importer'); ?></option>
                                <option value="manual" <?php selected($feed ? $feed->getSchedule() : 'hourly', 'manual'); ?>><?php esc_html_e('Manual Only', 'wpcalibrate-rss-importer'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="batch_size"><?php esc_html_e('Batch Size', 'wpcalibrate-rss-importer'); ?></label></th>
                        <td>
                            <input name="batch_size" type="number" id="batch_size" min="1" max="100" value="<?php echo esc_attr((string) ($feed ? $feed->getBatchSize() : 10)); ?>" />
                            <p class="wpcri-help-text"><?php esc_html_e('Maximum number of items to import per run (1 to 100).', 'wpcalibrate-rss-importer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Taxonomy Mapping', 'wpcalibrate-rss-importer'); ?></th>
                        <td>
                            <label>
                                <input name="map_categories" type="checkbox" value="1" <?php checked($feed ? $feed->isMapCategories() : false); ?> />
                                <?php esc_html_e('Map feed categories to WordPress taxonomy terms', 'wpcalibrate-rss-importer'); ?>
                            </label><br />
                            <label>
                                <input name="auto_create_terms" type="checkbox" value="1" <?php checked($feed ? $feed->isAutoCreateTerms() : false); ?> />
                                <?php esc_html_e('Automatically create new taxonomy terms if they do not exist', 'wpcalibrate-rss-importer'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Existing Post Updates', 'wpcalibrate-rss-importer'); ?></th>
                        <td>
                            <select name="update_mode" id="update_mode">
                                <option value="never" <?php selected($feed ? $feed->getUpdateMode() : 'never', 'never'); ?>><?php esc_html_e('Never update imported posts', 'wpcalibrate-rss-importer'); ?></option>
                                <option value="on_change" <?php selected($feed ? $feed->getUpdateMode() : 'never', 'on_change'); ?>><?php esc_html_e('Update imported posts when feed content changes', 'wpcalibrate-rss-importer'); ?></option>
                            </select>
                            <p class="wpcri-help-text"><?php esc_html_e('Only importer-managed fields are updated; user modifications to other fields are preserved.', 'wpcalibrate-rss-importer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Image Importing', 'wpcalibrate-rss-importer'); ?></th>
                        <td>
                            <?php $imgConfig = $feed ? $feed->getImageConfig() : FeedSource::defaultImageConfig(); ?>
                            <label>
                                <input name="image_config[import_images]" type="checkbox" value="1" <?php checked(!empty($imgConfig['import_images'])); ?> />
                                <?php esc_html_e('Import images from feed into Media Library', 'wpcalibrate-rss-importer'); ?>
                            </label><br />
                            <label>
                                <input name="image_config[set_featured_image]" type="checkbox" value="1" <?php checked(!empty($imgConfig['set_featured_image'])); ?> />
                                <?php esc_html_e('Set discovered image as post Featured Image', 'wpcalibrate-rss-importer'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Attribution & Canonical', 'wpcalibrate-rss-importer'); ?></th>
                        <td>
                            <?php $attr = $feed ? $feed->getAttribution() : FeedSource::defaultAttribution(); ?>
                            <label>
                                <input name="attribution[enabled]" type="checkbox" value="1" <?php checked(!empty($attr['enabled'])); ?> />
                                <?php esc_html_e('Append source attribution link to imported content', 'wpcalibrate-rss-importer'); ?>
                            </label><br />
                            <label>
                                <input name="use_canonical" type="checkbox" value="1" <?php checked($feed ? $feed->isUseCanonical() : false); ?> />
                                <?php esc_html_e('Use original article URL as canonical URL reference', 'wpcalibrate-rss-importer'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Content Filtering', 'wpcalibrate-rss-importer'); ?></th>
                        <td>
                            <?php $filtering = $feed ? $feed->getFiltering() : FeedSource::defaultFiltering(); ?>
                            <label for="filter_include"><strong><?php esc_html_e('Include Keywords (comma-separated):', 'wpcalibrate-rss-importer'); ?></strong></label><br />
                            <input name="filtering[include_keywords]" type="text" id="filter_include" value="<?php echo esc_attr($filtering['include_keywords'] ?? ''); ?>" class="large-text" /><br /><br />

                            <label for="filter_exclude"><strong><?php esc_html_e('Exclude Keywords (comma-separated):', 'wpcalibrate-rss-importer'); ?></strong></label><br />
                            <input name="filtering[exclude_keywords]" type="text" id="filter_exclude" value="<?php echo esc_attr($filtering['exclude_keywords'] ?? ''); ?>" class="large-text" /><br /><br />

                            <label for="max_age_days"><strong><?php esc_html_e('Max Article Age (Days):', 'wpcalibrate-rss-importer'); ?></strong></label><br />
                            <input name="filtering[max_age_days]" type="number" id="max_age_days" min="0" value="<?php echo esc_attr((string) ($filtering['max_age_days'] ?? 0)); ?>" />
                            <span class="wpcri-help-text"><?php esc_html_e('(0 = no age restriction)', 'wpcalibrate-rss-importer'); ?></span>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php echo $isEdit ? esc_html__('Update Feed', 'wpcalibrate-rss-importer') : esc_html__('Save Feed', 'wpcalibrate-rss-importer'); ?></button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Render Settings tab.
     */
    private function renderSettingsTab(): void {
        $settings = $this->settingsManager->getAll();
        $authors  = get_users(['capability' => 'edit_posts', 'fields' => ['ID', 'display_name']]);
        ?>
        <div class="wpcri-card">
            <h2><?php esc_html_e('Global Plugin Settings', 'wpcalibrate-rss-importer'); ?></h2>
            <form method="post" action="">
                <?php wp_nonce_field('wpcri_save_settings_nonce'); ?>
                <input type="hidden" name="wpcri_action" value="save_settings" />

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="default_post_status"><?php esc_html_e('Default Post Status', 'wpcalibrate-rss-importer'); ?></label></th>
                        <td>
                            <select name="default_post_status" id="default_post_status">
                                <option value="draft" <?php selected($settings['default_post_status'], 'draft'); ?>><?php esc_html_e('Draft', 'wpcalibrate-rss-importer'); ?></option>
                                <option value="pending" <?php selected($settings['default_post_status'], 'pending'); ?>><?php esc_html_e('Pending Review', 'wpcalibrate-rss-importer'); ?></option>
                                <option value="publish" <?php selected($settings['default_post_status'], 'publish'); ?>><?php esc_html_e('Publish', 'wpcalibrate-rss-importer'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="default_author_id"><?php esc_html_e('Default Author', 'wpcalibrate-rss-importer'); ?></label></th>
                        <td>
                            <select name="default_author_id" id="default_author_id">
                                <?php foreach ($authors as $author) : ?>
                                    <option value="<?php echo esc_attr((string) $author->ID); ?>" <?php selected($settings['default_author_id'], $author->ID); ?>>
                                        <?php echo esc_html($author->display_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="default_batch_size"><?php esc_html_e('Default Batch Size', 'wpcalibrate-rss-importer'); ?></label></th>
                        <td>
                            <input name="default_batch_size" type="number" id="default_batch_size" min="1" max="100" value="<?php echo esc_attr((string) $settings['default_batch_size']); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="http_timeout"><?php esc_html_e('HTTP Request Timeout (seconds)', 'wpcalibrate-rss-importer'); ?></label></th>
                        <td>
                            <input name="http_timeout" type="number" id="http_timeout" min="5" max="60" value="<?php echo esc_attr((string) $settings['http_timeout']); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="log_retention_days"><?php esc_html_e('Log Retention (Days)', 'wpcalibrate-rss-importer'); ?></label></th>
                        <td>
                            <input name="log_retention_days" type="number" id="log_retention_days" min="1" max="365" value="<?php echo esc_attr((string) $settings['log_retention_days']); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Data Retention on Uninstall', 'wpcalibrate-rss-importer'); ?></th>
                        <td>
                            <label>
                                <input name="delete_data_on_uninstall" type="checkbox" value="1" <?php checked(!empty($settings['delete_data_on_uninstall'])); ?> />
                                <?php esc_html_e('Delete plugin data when WPCalibrate RSS Importer is uninstalled', 'wpcalibrate-rss-importer'); ?>
                            </label>
                            <p class="wpcri-help-text"><?php esc_html_e('Default is OFF. When enabled, uninstallation removes feed configurations, logs, and plugin options. Imported posts and media are always preserved.', 'wpcalibrate-rss-importer'); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Save Settings', 'wpcalibrate-rss-importer'); ?></button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Render Logs tab.
     */
    private function renderLogsTab(): void {
        $severity = sanitize_key($_GET['severity'] ?? '');
        $search   = sanitize_text_field((string) ($_GET['s'] ?? ''));
        $paged    = max(1, (int) ($_GET['paged'] ?? 1));

        $logsData = $this->logger->getLogs([
            'severity' => $severity,
            'search'   => $search,
            'paged'    => $paged,
            'per_page' => 25,
        ]);
        $items = $logsData['items'];
        $total = $logsData['total'];
        ?>
        <div class="wpcri-card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                <h2 style="margin:0; border:none; padding:0;"><?php esc_html_e('Activity & Diagnostic Logs', 'wpcalibrate-rss-importer'); ?></h2>
                <form method="post" action="">
                    <?php wp_nonce_field('wpcri_clear_logs_nonce'); ?>
                    <input type="hidden" name="wpcri_action" value="clear_logs" />
                    <button type="submit" class="button button-secondary wpcri-confirm-action" data-confirm="<?php esc_attr_e('Are you sure you want to clear all activity logs?', 'wpcalibrate-rss-importer'); ?>">
                        <?php esc_html_e('Clear Logs', 'wpcalibrate-rss-importer'); ?>
                    </button>
                </form>
            </div>

            <!-- Filter Controls -->
            <form method="get" action="" style="display:flex; gap:12px; margin-bottom:16px; align-items:center;">
                <input type="hidden" name="page" value="wpcalibrate-rss-importer" />
                <input type="hidden" name="tab" value="logs" />
                <select name="severity">
                    <option value=""><?php esc_html_e('All Severities', 'wpcalibrate-rss-importer'); ?></option>
                    <option value="info" <?php selected($severity, 'info'); ?>><?php esc_html_e('Info', 'wpcalibrate-rss-importer'); ?></option>
                    <option value="success" <?php selected($severity, 'success'); ?>><?php esc_html_e('Success', 'wpcalibrate-rss-importer'); ?></option>
                    <option value="warning" <?php selected($severity, 'warning'); ?>><?php esc_html_e('Warning', 'wpcalibrate-rss-importer'); ?></option>
                    <option value="error" <?php selected($severity, 'error'); ?>><?php esc_html_e('Error', 'wpcalibrate-rss-importer'); ?></option>
                </select>
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search logs...', 'wpcalibrate-rss-importer'); ?>" />
                <button type="submit" class="button"><?php esc_html_e('Filter', 'wpcalibrate-rss-importer'); ?></button>
            </form>

            <?php if (empty($items)) : ?>
                <p><?php esc_html_e('No activity logs recorded.', 'wpcalibrate-rss-importer'); ?></p>
            <?php else : ?>
                <table class="wpcri-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Time', 'wpcalibrate-rss-importer'); ?></th>
                            <th><?php esc_html_e('Severity', 'wpcalibrate-rss-importer'); ?></th>
                            <th><?php esc_html_e('Source ID', 'wpcalibrate-rss-importer'); ?></th>
                            <th><?php esc_html_e('Message', 'wpcalibrate-rss-importer'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $entry) : ?>
                            <tr>
                                <td><?php echo esc_html($entry['timestamp']); ?></td>
                                <td>
                                    <?php
                                    $sevBadge = match ($entry['severity']) {
                                        'success' => 'wpcri-badge-enabled',
                                        'error'   => 'wpcri-badge-disabled',
                                        'warning' => 'wpcri-badge-warning',
                                        default   => 'wpcri-badge-info',
                                    };
                                    ?>
                                    <span class="wpcri-badge <?php echo esc_attr($sevBadge); ?>"><?php echo esc_html(strtoupper($entry['severity'])); ?></span>
                                </td>
                                <td><?php echo esc_html($entry['source_id'] > 0 ? (string) $entry['source_id'] : '—'); ?></td>
                                <td><?php echo esc_html($entry['message']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render License tab.
     */
    private function renderLicenseTab(): void {
        $licenseState = $this->licenseManager->getLicenseState();
        $isConfigured = $this->licenseManager->isProviderConfigured();
        ?>
        <div class="wpcri-card">
            <h2><?php esc_html_e('License Management', 'wpcalibrate-rss-importer'); ?></h2>
            <p><?php esc_html_e('Manage your WPCalibrate Pro extension license key when applicable.', 'wpcalibrate-rss-importer'); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Licensing Provider', 'wpcalibrate-rss-importer'); ?></th>
                    <td>
                        <span class="wpcri-badge <?php echo $isConfigured ? 'wpcri-badge-enabled' : 'wpcri-badge-warning'; ?>">
                            <?php echo esc_html($this->licenseManager->getProviderName()); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Status', 'wpcalibrate-rss-importer'); ?></th>
                    <td>
                        <strong><?php echo esc_html(ucfirst($licenseState['status'])); ?></strong>
                        <p class="wpcri-help-text">
                            <?php if (!$isConfigured) : ?>
                                <?php esc_html_e('No external licensing provider is configured in this free version. Live activations are not simulated.', 'wpcalibrate-rss-importer'); ?>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
            </table>

            <form method="post" action="">
                <?php wp_nonce_field('wpcri_save_license_nonce'); ?>
                <input type="hidden" name="wpcri_action" value="save_license" />

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="license_key"><?php esc_html_e('License Key', 'wpcalibrate-rss-importer'); ?></label></th>
                        <td>
                            <input name="license_key" type="password" id="license_key" value="" placeholder="<?php echo esc_attr($licenseState['key_masked'] ?: 'Enter license key...'); ?>" class="regular-text" />
                            <p class="wpcri-help-text"><?php esc_html_e('Stored license keys are masked for security.', 'wpcalibrate-rss-importer'); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Save License Key', 'wpcalibrate-rss-importer'); ?></button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Render Support tab.
     */
    private function renderSupportTab(): void {
        $diag = SupportManager::getSystemDiagnostics();
        ?>
        <div class="wpcri-card">
            <h2><?php esc_html_e('WPCalibrate Support & Contact', 'wpcalibrate-rss-importer'); ?></h2>
            <p><?php esc_html_e('Need assistance or have a feature request? Reach out directly to our engineering and support team.', 'wpcalibrate-rss-importer'); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Support Email', 'wpcalibrate-rss-importer'); ?></th>
                    <td>
                        <a href="<?php echo esc_url('mailto:' . SupportManager::SUPPORT_EMAIL); ?>" class="button button-secondary">
                            <?php echo esc_html(SupportManager::SUPPORT_EMAIL); ?>
                        </a>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Official Website', 'wpcalibrate-rss-importer'); ?></th>
                    <td>
                        <a href="<?php echo esc_url(SupportManager::WEBSITE_URL); ?>" target="_blank" rel="noopener noreferrer">
                            <?php echo esc_html(SupportManager::WEBSITE_URL); ?>
                        </a>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Marketplace', 'wpcalibrate-rss-importer'); ?></th>
                    <td>
                        <a href="<?php echo esc_url(SupportManager::MARKETPLACE); ?>" target="_blank" rel="noopener noreferrer">
                            <?php echo esc_html(SupportManager::MARKETPLACE); ?>
                        </a>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Phone / WhatsApp', 'wpcalibrate-rss-importer'); ?></th>
                    <td>
                        <a href="<?php echo esc_url('https://wa.me/' . str_replace('+', '', SupportManager::PHONE_WHATSAPP)); ?>" target="_blank" rel="noopener noreferrer">
                            <?php echo esc_html(SupportManager::PHONE_WHATSAPP); ?>
                        </a>
                    </td>
                </tr>
            </table>

            <h3 style="margin-top:32px; border-bottom:1px solid #f0f0f1; padding-bottom:8px;"><?php esc_html_e('System Diagnostics Environment', 'wpcalibrate-rss-importer'); ?></h3>
            <table class="wpcri-table widefat striped" style="max-width:700px;">
                <tbody>
                    <?php foreach ($diag as $key => $val) : ?>
                        <tr>
                            <td style="width:250px;"><strong><?php echo esc_html(ucwords(str_replace('_', ' ', $key))); ?></strong></td>
                            <td><?php echo esc_html($val); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Action handler: Save Feed.
     */
    private function handleSaveFeed(): void {
        $feedId = (int) ($_POST['feed_id'] ?? 0);
        $name   = sanitize_text_field((string) ($_POST['feed_name'] ?? ''));
        $url    = esc_url_raw(trim((string) ($_POST['feed_url'] ?? '')));

        if (empty($name) || empty($url)) {
            $this->addNotice('error', __('Feed name and URL are required.', 'wpcalibrate-rss-importer'));
            return;
        }

        $filtering = [
            'include_keywords'   => sanitize_text_field((string) ($_POST['filtering']['include_keywords'] ?? '')),
            'exclude_keywords'   => sanitize_text_field((string) ($_POST['filtering']['exclude_keywords'] ?? '')),
            'keyword_match_type' => 'any',
            'filter_target'      => 'title_content',
            'category_filter'    => '',
            'max_age_days'       => max(0, (int) ($_POST['filtering']['max_age_days'] ?? 0)),
        ];

        $imageConfig = [
            'import_images'      => !empty($_POST['image_config']['import_images']),
            'set_featured_image' => !empty($_POST['image_config']['set_featured_image']),
            'max_image_size_kb'  => 5120,
            'allowed_mimes'      => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        ];

        $attribution = [
            'enabled' => !empty($_POST['attribution']['enabled']),
            'format'  => FeedSource::defaultAttribution()['format'],
        ];

        $feedSource = new FeedSource([
            'id'                    => $feedId,
            'name'                  => $name,
            'url'                   => $url,
            'enabled'               => !empty($_POST['enabled']),
            'destination_post_type' => sanitize_key((string) ($_POST['destination_post_type'] ?? 'post')),
            'destination_status'    => sanitize_key((string) ($_POST['destination_status'] ?? 'draft')),
            'author_id'             => max(1, (int) ($_POST['author_id'] ?? 1)),
            'map_categories'        => !empty($_POST['map_categories']),
            'auto_create_terms'     => !empty($_POST['auto_create_terms']),
            'filtering'             => $filtering,
            'image_config'          => $imageConfig,
            'attribution'           => $attribution,
            'use_canonical'         => !empty($_POST['use_canonical']),
            'update_mode'           => sanitize_key((string) ($_POST['update_mode'] ?? 'never')),
            'schedule'              => sanitize_key((string) ($_POST['schedule'] ?? 'hourly')),
            'batch_size'            => max(1, min(100, (int) ($_POST['batch_size'] ?? 10))),
        ]);

        $savedId = $this->feedRepository->save($feedSource);
        if ($savedId > 0) {
            $this->addNotice('success', __('Feed source saved successfully.', 'wpcalibrate-rss-importer'));
            wp_safe_redirect(admin_url('admin.php?page=wpcalibrate-rss-importer&tab=feeds'));
            exit;
        } else {
            $this->addNotice('error', __('Failed to save feed source.', 'wpcalibrate-rss-importer'));
        }
    }

    /**
     * Action handler: Delete Feed.
     */
    private function handleDeleteFeed(): void {
        $feedId = (int) ($_POST['feed_id'] ?? 0);
        if ($feedId > 0 && $this->feedRepository->delete($feedId)) {
            $this->addNotice('success', __('Feed source deleted. Previously imported posts are preserved.', 'wpcalibrate-rss-importer'));
        } else {
            $this->addNotice('error', __('Failed to delete feed source.', 'wpcalibrate-rss-importer'));
        }
    }

    /**
     * Action handler: Toggle pause/enable.
     */
    private function handleToggleFeed(): void {
        $feedId = (int) ($_POST['feed_id'] ?? 0);
        $feed   = $this->feedRepository->get($feedId);
        if ($feed instanceof FeedSource) {
            $data = $feed->toArray();
            $data['enabled'] = !$feed->isEnabled();
            $updated = new FeedSource($data);
            $this->feedRepository->save($updated);
            $this->addNotice('success', $updated->isEnabled() ? __('Feed enabled.', 'wpcalibrate-rss-importer') : __('Feed paused.', 'wpcalibrate-rss-importer'));
        }
    }

    /**
     * Action handler: Manual Import Now.
     */
    private function handleImportNow(): void {
        $feedId = (int) ($_POST['feed_id'] ?? 0);
        $feed   = $this->feedRepository->get($feedId);
        if (!$feed instanceof FeedSource) {
            $this->addNotice('error', __('Feed source not found.', 'wpcalibrate-rss-importer'));
            return;
        }

        $result = $this->importManager->importBatch($feed, false);
        if ($result['success']) {
            $this->addNotice('success', $result['message']);
        } else {
            $this->addNotice('error', $result['message']);
        }
    }

    /**
     * Action handler: Save Global Settings.
     */
    private function handleSaveSettings(): void {
        $this->settingsManager->update($_POST);
        $this->addNotice('success', __('Plugin settings updated successfully.', 'wpcalibrate-rss-importer'));
    }

    private function addNotice(string $type, string $message): void {
        $this->notices[] = ['type' => $type, 'message' => $message];
    }

    private function renderNotices(): void {
        foreach ($this->notices as $notice) {
            $class = match ($notice['type']) {
                'error'   => 'notice-error',
                'warning' => 'notice-warning',
                'info'    => 'notice-info',
                default   => 'notice-success',
            };
            ?>
            <div class="notice <?php echo esc_attr($class); ?> is-dismissible">
                <p><?php echo esc_html($notice['message']); ?></p>
            </div>
            <?php
        }
    }
}
