<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin orchestrator class.
 */
final class Plugin {

    private static ?Plugin $instance = null;

    private Admin\MenuManager $menuManager;
    private Admin\AdminController $adminController;
    private Feed\FeedRepository $feedRepository;
    private Import\ImportManager $importManager;
    private Scheduling\ScheduleManager $scheduleManager;
    private Logging\Logger $logger;
    private Settings\SettingsManager $settingsManager;
    private Licensing\LicenseManager $licenseManager;

    private bool $initialized = false;

    private function __construct() {
        $this->settingsManager = new Settings\SettingsManager();
        $this->logger          = new Logging\Logger($this->settingsManager);
        $this->feedRepository  = new Feed\FeedRepository();
        $this->scheduleManager = new Scheduling\ScheduleManager($this->settingsManager, $this->logger);
        $this->licenseManager  = new Licensing\LicenseManager();
        $this->importManager   = new Import\ImportManager(
            $this->feedRepository,
            $this->settingsManager,
            $this->logger,
            $this->scheduleManager
        );
        $this->adminController = new Admin\AdminController(
            $this->feedRepository,
            $this->importManager,
            $this->settingsManager,
            $this->logger,
            $this->licenseManager,
            $this->scheduleManager
        );
        $this->menuManager     = new Admin\MenuManager($this->adminController);
    }

    public static function getInstance(): Plugin {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize plugin hooks and services.
     */
    public function init(): void {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;

        // Load translations
        add_action('init', [$this, 'loadTextdomain']);

        // Check for database schema / version upgrades
        add_action('init', [Activation\Upgrader::class, 'checkVersion']);

        // Register post types (feed source storage)
        add_action('init', [$this->feedRepository, 'registerPostType']);

        // Initialize admin menu, pages, and GitHub updater
        if (is_admin()) {
            $this->menuManager->init();
            $this->adminController->init();
            (new Updater\GitHubUpdater(WPCRI_PLUGIN_FILE, WPCRI_VERSION))->init();
        }

        // Initialize scheduling and cron dispatcher
        $this->scheduleManager->init();

        /**
         * Action fired after WPCalibrate RSS Importer has fully initialized.
         *
         * @param Plugin $plugin Main plugin instance.
         */
        do_action('wpcri_initialized', $this);
    }

    /**
     * Load plugin text domain.
     */
    public function loadTextdomain(): void {
        load_plugin_textdomain(
            'wpcalibrate-rss-importer',
            false,
            dirname(WPCRI_PLUGIN_BASENAME) . '/languages'
        );
    }

    // Getters for dependency injection / service access
    public function getFeedRepository(): Feed\FeedRepository {
        return $this->feedRepository;
    }

    public function getImportManager(): Import\ImportManager {
        return $this->importManager;
    }

    public function getScheduleManager(): Scheduling\ScheduleManager {
        return $this->scheduleManager;
    }

    public function getLogger(): Logging\Logger {
        return $this->logger;
    }

    public function getSettingsManager(): Settings\SettingsManager {
        return $this->settingsManager;
    }

    public function getLicenseManager(): Licensing\LicenseManager {
        return $this->licenseManager;
    }

    public function getAdminController(): Admin\AdminController {
        return $this->adminController;
    }
}
