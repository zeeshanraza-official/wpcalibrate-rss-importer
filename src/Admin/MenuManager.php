<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages WPCalibrate parent menu registration and RSS Importer submenu isolation.
 */
final class MenuManager {

    public const PARENT_MENU_SLUG = 'wpcalibrate';
    public const PLUGIN_PAGE_SLUG = 'wpcalibrate-rss-importer';

    private AdminController $adminController;

    public function __construct(AdminController $adminController) {
        $this->adminController = $adminController;
    }

    public function init(): void {
        // Priority 9 to cooperate gracefully with other WPCalibrate plugins
        add_action('admin_menu', [$this, 'registerMenus'], 9);
        add_action('admin_head', [$this, 'outputMenuStyles']);
    }

    /**
     * Output CSS to properly size and align the WPCalibrate branding icon in the admin sidebar.
     */
    public function outputMenuStyles(): void {
        ?>
        <style>
            #adminmenu .toplevel_page_wpcalibrate .wp-menu-image img {
                width: 20px;
                height: 20px;
                padding: 7px 0 0 0;
                opacity: 0.85;
                object-fit: contain;
                transition: opacity 0.2s ease;
            }
            #adminmenu .toplevel_page_wpcalibrate:hover .wp-menu-image img,
            #adminmenu .toplevel_page_wpcalibrate.wp-has-current-submenu .wp-menu-image img {
                opacity: 1;
            }
        </style>
        <?php
    }

    /**
     * Register WPCalibrate menu and RSS Importer submenu.
     */
    public function registerMenus(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $iconUrl = WPCRI_PLUGIN_URL . 'branding/icon-white.png';

        // Check if WPCalibrate parent menu already exists
        if (!$this->parentMenuExists()) {
            add_menu_page(
                __('WPCalibrate', 'wpcalibrate-rss-importer'),
                __('WPCalibrate', 'wpcalibrate-rss-importer'),
                'manage_options',
                self::PARENT_MENU_SLUG,
                [$this, 'renderParentPage'],
                $iconUrl,
                30
            );
        }

        // Register plugin submenu page
        add_submenu_page(
            self::PARENT_MENU_SLUG,
            __('RSS Importer', 'wpcalibrate-rss-importer'),
            __('RSS Importer', 'wpcalibrate-rss-importer'),
            'manage_options',
            self::PLUGIN_PAGE_SLUG,
            [$this->adminController, 'renderPage']
        );
    }

    /**
     * Check if the top-level WPCalibrate menu item is already registered in WordPress global $menu.
     */
    private function parentMenuExists(): bool {
        global $menu;
        if (!is_array($menu)) {
            return false;
        }

        foreach ($menu as $item) {
            if (isset($item[2]) && $item[2] === self::PARENT_MENU_SLUG) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fallback callback for top-level WPCalibrate menu when accessed directly.
     * Redirects smoothly to this plugin or renders a hub overview.
     */
    public function renderParentPage(): void {
        wp_safe_redirect(admin_url('admin.php?page=' . self::PLUGIN_PAGE_SLUG));
        exit;
    }
}
