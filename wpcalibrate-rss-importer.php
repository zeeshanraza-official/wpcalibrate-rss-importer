<?php
/**
 * Plugin Name:       WPCalibrate RSS Importer
 * Plugin URI:        https://wpcalibrate.com/plugins/rss-importer
 * Description:       Import RSS and Atom feeds into WordPress posts, pages, or custom post types with advanced filtering, image processing, deduplication, and flexible scheduling.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.2
 * Author:            WPCalibrate
 * Author URI:        https://wpcalibrate.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpcalibrate-rss-importer
 * Domain Path:       /languages
 * GitHub Plugin URI: https://github.com/zeeshanraza-official/wpcalibrate-rss-importer
 * Primary Branch:    main
 */

declare(strict_types=1);

namespace WPCalibrate\RSSImporter;

if (!defined('ABSPATH')) {
    exit;
}

// Plugin Constants
define('WPCRI_VERSION', '1.0.0');
define('WPCRI_MIN_PHP', '8.2');
define('WPCRI_MIN_WP', '6.2');
define('WPCRI_PLUGIN_FILE', __FILE__);
define('WPCRI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPCRI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPCRI_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('WPCRI_OPTION_PREFIX', 'wpcri_');
define('WPCRI_META_PREFIX', '_wpcri_');

/**
 * Autoloader for WPCalibrate\RSSImporter classes.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'WPCalibrate\\RSSImporter\\';
    $len = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = WPCRI_PLUGIN_DIR . 'src/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * Verify PHP requirements before initializing the plugin.
 */
if (version_compare(PHP_VERSION, WPCRI_MIN_PHP, '<')) {
    add_action('admin_notices', static function (): void {
        ?>
        <div class="notice notice-error">
            <p>
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: 1: Plugin name, 2: Required PHP version, 3: Current PHP version */
                        __('%1$s requires PHP version %2$s or higher. Your server is running PHP version %3$s. Please upgrade your PHP version.', 'wpcalibrate-rss-importer'),
                        'WPCalibrate RSS Importer',
                        WPCRI_MIN_PHP,
                        PHP_VERSION
                    )
                );
                ?>
            </p>
        </div>
        <?php
    });
    return;
}

/**
 * Activation Hook.
 */
register_activation_hook(WPCRI_PLUGIN_FILE, static function (): void {
    Activation\Activator::activate();
});

/**
 * Deactivation Hook.
 */
register_deactivation_hook(WPCRI_PLUGIN_FILE, static function (): void {
    Activation\Deactivator::deactivate();
});

/**
 * Bootstrap plugin on plugins_loaded.
 */
add_action('plugins_loaded', static function (): void {
    Plugin::getInstance()->init();
});
