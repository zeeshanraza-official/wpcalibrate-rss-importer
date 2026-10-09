=== WPCalibrate RSS Importer ===
Contributors: wpcalibrate
Tags: rss, atom, feed, importer, rss importer
Requires at least: 6.2
Tested up to: 7.1.3
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import unlimited RSS and Atom feeds into WordPress posts, pages, or compatible custom post types with deduplication, filtering, and scheduling.

== Description ==

WPCalibrate RSS Importer is a robust, production-quality WordPress plugin designed to reliably import content from unlimited RSS 2.0 and Atom feeds into your WordPress website.

Whether you need to curate industry news, syndicate company announcements, or aggregate multiple blog feeds into standard posts, pages, or custom post types, WPCalibrate RSS Importer provides automated ingestion with strict duplicate protection, SSRF safety, and media handling.

### Key Features

* **Unlimited Feed Sources**: Configure and manage as many RSS and Atom feeds as needed.
* **Flexible Destination Types**: Import directly into Posts, Pages, or compatible public Custom Post Types.
* **Post Status & Author Control**: Set imported content as Draft, Pending Review, or Published, with configurable author assignment.
* **Smart Deduplication**: Durable source identification combining GUIDs, normalized URLs, and fallback content fingerprints ensures duplicates are never created across cron or manual runs.
* **Featured Image Processing**: Automatically discovers images from Media RSS, enclosures, or content; validates MIME types and file sizes; sideloads into the Media Library; and sets Featured Images.
* **Keyword & Category Filtering**: Filter incoming feed items by include/exclude keywords, category matches, or publication age before creating posts.
* **Flexible Automated Scheduling**: Choose between 15-minute, 30-minute, hourly, twice daily, or daily intervals powered by WP-Cron.
* **Manual Import Now**: Trigger instant batch processing on demand with real-time feedback.
* **Attribution & Canonical Support**: Optionally append customized source attribution links and set canonical URL references to preserve original source attribution.
* **Existing Post Updates**: Configurable update mode allows existing posts to sync when feed items change, preserving manually modified user fields.
* **Diagnostic Logging**: Clean, bounded event logging tracking import successes, skips, and errors with automated 30-day retention pruning.
* **Secure Architecture**: Built-in SSRF mitigation blocking private and internal IP ranges, XML external entity (XXE) protection, and `wp_kses_post` HTML sanitization.
* **Clean Data Retention**: Opt-in uninstall cleanup ensures your imported content is never lost accidentally.

== Installation ==

1. Upload the `wpcalibrate-rss-importer` folder to your `/wp-content/plugins/` directory, or install the ZIP file via **Plugins > Add New > Upload Plugin**.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Access the plugin interface by navigating to **WPCalibrate > RSS Importer** in your WordPress admin dashboard.

== Adding a Feed ==

1. In the WordPress dashboard, go to **WPCalibrate > RSS Importer**.
2. Click the **Add Feed** tab.
3. Enter a descriptive **Feed Name** and the remote **Feed URL** (HTTP or HTTPS).
4. Use the **Test Feed** tool to verify connectivity and preview feed title and item count.
5. Configure your preferred destination post type, publication status, author, schedule, and batch size.
6. Set optional filtering rules (include/exclude keywords, maximum article age) and image importing preferences.
7. Click **Save Feed**.

== Scheduling & WP-Cron Limitations ==

Automated feed imports rely on WordPress Cron (WP-Cron).

* **How WP-Cron Works**: WP-Cron executes scheduled tasks when visitors browse your website.
* **Low-Traffic Sites**: On sites with infrequent traffic, scheduled imports may run only when a visit occurs.
* **High-Reliability Recommendation**: For sites requiring precision timing, configure a real system crontab on your hosting server calling `wp-cron.php` every 15 minutes (and define `DISABLE_WP_CRON` as true in `wp-config.php`).

== Data Retention & Uninstallation ==

By default, deactivating or deleting WPCalibrate RSS Importer **will not** delete your imported posts, media items, or feed configurations.

If you wish to remove all plugin configuration upon deletion:
1. Navigate to **WPCalibrate > RSS Importer > Settings**.
2. Enable **Delete plugin data when WPCalibrate RSS Importer is uninstalled**.
3. Save settings.

When this option is enabled, uninstalling the plugin removes feed source records, plugin options, logs, and temporary locks. Your imported WordPress posts and media library files remain intact to safeguard your published site content.

== Privacy & Security ==

* **Remote Requests**: The plugin sends HTTP GET requests only to the administrator-configured feed URLs. No site telemetry or user information is transmitted to WPCalibrate or third parties.
* **Content Sanitization**: All feed titles and content are sanitized using WordPress core KSES rules to neutralize executable scripts, dangerous HTML tags, and event handlers.
* **SSRF Protection**: Requests to localhost, private IP subnets, and cloud metadata services are blocked.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =
No. WooCommerce is completely optional. If WooCommerce is installed, internal shop post types (such as orders and coupons) are safely excluded to prevent invalid records.

= Can I import full articles from websites without feeds? =
No. Version 1.0 imports content provided directly within the RSS or Atom feed. Web scraping of external publisher pages is not supported.

= Will deleting a feed source delete previously imported posts? =
No. Deleting a feed source removes only the feed configuration. Previously imported posts and media files remain in your WordPress database.

= How does duplicate prevention work? =
Each item is assigned a deterministic fingerprint based on the feed source, GUID, or normalized URL. Before creating a post, the database is queried to ensure no existing post shares that identity.

== Support ==

For technical questions, bug reports, or feature suggestions:
* **Support Email**: support@wpcalibrate.com
* **Website**: https://wpcalibrate.com
* **Marketplace**: https://marketplace.wpcalibrate.com/
* **Phone / WhatsApp**: +447474795976

== Changelog ==

= 1.0.0 =
* Initial public release.
* Support for unlimited RSS 2.0 and Atom feed sources.
* Granular scheduling (15m, 30m, hourly, twice daily, daily, manual).
* Concurrency locking with stale lock recovery.
* Media RSS and content image featured-image importing with deduplication.
* Advanced keyword, category, and date filtering.
* Idempotent existing post update mode.
* Bounded diagnostic logging and retention pruning.
