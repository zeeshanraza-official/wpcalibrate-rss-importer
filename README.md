# WPCalibrate RSS Importer

[![WordPress](https://img.shields.io/badge/WordPress-6.2%20to%207.1.3-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3-8892BF.svg)](https://php.net)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](LICENSE.txt)
[![Build Status](https://img.shields.io/badge/tests-8%2F8%20passing-brightgreen.svg)]()

> A robust, enterprise-grade content ingestion engine for WordPress. Ingest content from unlimited RSS 2.0 and Atom feeds into posts, pages, or custom post types with intelligent deduplication, advanced keyword & taxonomy filtering, Media RSS sideloading, and automated background scheduling.

---

## About

**WPCalibrate RSS Importer** is engineered from the ground up for publishers, aggregators, corporate intranets, and content curators who require continuous, reliable feed ingestion without artificial limitations or performance penalties.

Unlike conventional plugins that enforce arbitrary feed caps or lock essential settings behind paywalls, WPCalibrate RSS Importer offers unlimited feed source configurations out of the box. Stored natively within WordPress private post types (`wpcri_feed`), your feed sources never introduce database lock-in or pollute your environment with fragile custom SQL schemas. Deleting a feed source configuration will never remove previously imported content or media assets.

Built specifically for modern PHP (8.2 and 8.3) and modern WordPress (up to WordPress 7.1.3), the plugin utilizes memory-friendly stream parsing, cryptographic fingerprint deduplication, safe media sideloading, and resilient concurrency locking.

- **Developer:** [WPCalibrate](https://wpcalibrate.com)
- **Author Website:** [https://wpcalibrate.com](https://wpcalibrate.com)
- **Support:** [support@wpcalibrate.com](mailto:support@wpcalibrate.com)
- **Marketplace:** [https://marketplace.wpcalibrate.com/](https://marketplace.wpcalibrate.com/)

---

## Features

### 📡 Feed Ingestion & Compatibility
- **Unlimited Feed Sources:** Add, configure, test, and automate as many RSS 2.0, RSS 1.0, and Atom feeds as your server can handle.
- **Enterprise SSRF Protection:** Pre-flight DNS resolution blocks requests to internal IP ranges (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`), loopback (`127.0.0.0/8`, `::1`), link-local metadata endpoints (`169.254.169.254`), and non-HTTP/HTTPS protocols.
- **Robust XML Parsing & XXE Defense:** Parses remote payloads using PHP's `SimpleXML` configured with `LIBXML_NONET` and `LIBXML_NOCDATA`, eliminating XML entity injection vulnerabilities.
- **Interactive Feed Testing & Preview:** Test remote feed connectivity, inspect response headers, and preview the next 5 items with filter match results before saving or activating an automated schedule.

### 🎯 Filtering & Taxonomy Mapping
- **Keyword Filtering:** Define mandatory keywords (must contain) and exclusion keywords (must not contain) evaluated across titles and item descriptions.
- **Article Age Restrictions:** Automatically ignore stale or legacy items older than a designated number of days.
- **Custom Post Type Routing:** Ingest items into standard WordPress Posts, Pages, or compatible public Custom Post Types.
- **Dynamic Taxonomy Mapping:** Map feed categories into existing WordPress taxonomies (categories, tags, or custom taxonomies) with an optional flag to auto-create terms on the fly.
- **Post Author & Status Customization:** Assign imported items to any registered WordPress author and set initial post status (`draft`, `pending`, or `publish`).

### 🖼️ Media Discovery & Featured Images
- **Multi-Source Image Discovery:** Automatically detects featured imagery by traversing `<media:content>`, `<media:thumbnail>`, `<enclosure type="image/*">`, and HTML body `<img>` tags in priority order.
- **Attachment Deduplication:** Checks existing media attachments by source URL fingerprint to prevent duplicate uploads from accumulating in your Media Library.
- **Safe Sideloading:** Validates MIME types against WordPress allowed upload lists and enforces maximum file size ceilings before sideloading.

### 🛡️ Deduplication & Non-Destructive Updates
- **Multi-Tier Compound Identity:** Evaluates feed item uniqueness via Feed ID + GUID, fallback Feed ID + Normalized URL (tracking parameters like `utm_*` stripped), and fallback SHA-256 content hashing.
- **Idempotent Updates:** Optionally update previously imported posts when remote feeds publish revisions, modifying only importer-managed fields while strictly preserving manual user edits and custom post meta.

### ⚙️ Automation, Locking & Diagnostics
- **Flexible Scheduling:** Schedule feeds individually at intervals of every 15 minutes, every 30 minutes, hourly, twice daily, or daily, with support for manual "Import Now" runs.
- **Transient Concurrency Locking:** Prevents overlapping cron jobs using process tokens with automated stale-lock recovery timeouts.
- **Diagnostic Logging:** In-dashboard event log with filterable severity levels (`info`, `success`, `warning`, `error`), automatic 30-day retention pruning, and sensitive context redaction.
- **In-Dashboard GitHub Updates:** Seamless update notifications and one-click upgrades directly within the WordPress dashboard via GitHub Releases.

---

## Docs

### System Requirements
- **WordPress:** 6.2 or higher (fully tested and verified on WordPress 7.1.3)
- **PHP:** 8.2 or 8.3 (with `simplexml`, `curl`, and `mbstring` extensions enabled)
- **Database:** MySQL 5.7+ / MariaDB 10.3+

---

### Installation

#### Method 1: Upload via WordPress Admin
1. Download the latest release `wpcalibrate-rss-importer-1.0.0.zip` from the [Releases](https://github.com/zeeshanraza-official/wpcalibrate-rss-importer/releases) page.
2. In your WordPress dashboard, navigate to **Plugins > Add New > Upload Plugin**.
3. Choose the downloaded ZIP file and click **Install Now**.
4. Click **Activate Plugin**.

#### Method 2: Manual FTP / SFTP Installation
1. Extract `wpcalibrate-rss-importer-1.0.0.zip`.
2. Upload the `wpcalibrate-rss-importer` directory to your WordPress installation's `/wp-content/plugins/` directory.
3. In your WordPress dashboard, go to **Plugins > Installed Plugins** and activate **WPCalibrate RSS Importer**.

#### Method 3: GitHub Updater
If you have [GitHub Updater](https://github.com/afragen/github-updater) or [Git Updater](https://git-updater.com) installed, you can install and update directly using this repository URL:
```text
https://github.com/zeeshanraza-official/wpcalibrate-rss-importer
```

---

### Quick Start Guide

1. **Access the Hub:** In the WordPress admin menu, click on the **WPCalibrate** menu icon, or select **WPCalibrate > RSS Importer**.
2. **Review the Overview Tab:** Explore your real-time ingestion KPIs, cron dispatcher status, and architecture summary.
3. **Add a Feed:**
   - Click the **Add Feed** tab.
   - Enter a descriptive **Feed Name** (e.g. *TechCrunch Latest News*).
   - Enter the valid **Feed URL** (e.g. `https://techcrunch.com/feed/`).
   - Click **Test Feed** to verify reachability and inspect available items.
4. **Configure Ingestion Options:**
   - Select destination **Post Type** (Post, Page, etc.).
   - Choose initial **Post Status** (Draft, Pending Review, or Published).
   - Assign the default **Author**.
   - Set **Max Items Per Run** and **Schedule Frequency**.
5. **Set Filtering & Media (Optional):**
   - Provide comma-separated keywords in **Keywords to Include** or **Keywords to Exclude**.
   - Check **Import Featured Images** to sideload feed images automatically.
6. **Save Feed Source:** Click **Create Feed Source**. Your feed will begin ingesting automatically according to your chosen schedule.

---

### Developer Hooks & Extensibility

WPCalibrate RSS Importer provides extensive actions and filters for developer customization:

#### Actions
```php
// Fired before a feed import batch begins
do_action('wpcri_before_feed_import', FeedSource $feed);

// Fired after a single post is successfully created
do_action('wpcri_post_created', int $postId, FeedItem $item, FeedSource $feed);

// Fired after an existing post is updated
do_action('wpcri_post_updated', int $postId, FeedItem $item, FeedSource $feed);

// Fired when a feed import batch completes
do_action('wpcri_after_feed_import', FeedSource $feed, array $results);
```

#### Filters
```php
// Filter post data before insertion
add_filter('wpcri_pre_insert_post_data', function(array $postData, FeedItem $item, FeedSource $feed) {
    // Custom post modification logic
    return $postData;
}, 10, 3);

// Filter item filtering decision
add_filter('wpcri_filter_item_decision', function(bool $shouldImport, FeedItem $item, FeedSource $feed) {
    return $shouldImport;
}, 10, 3);

// Filter source attribution HTML
add_filter('wpcri_attribution_html', function(string $attributionHtml, FeedItem $item, FeedSource $feed) {
    return $attributionHtml;
}, 10, 3);
```

---

## Changelog

### [1.0.0] - 2026-10-09
- **Initial Release**
  - Unlimited RSS 2.0, RSS 1.0, and Atom feed ingestion engine.
  - Multi-tier deduplication using GUID, canonical URL normalization, and SHA-256 fallback.
  - Strict SSRF network guards blocking internal RFC 1918 subnets, IPv6 loopback, and metadata endpoints.
  - XXE prevention via `LIBXML_NONET` XML parser configuration.
  - Media RSS and content image sideloading with MIME validation and attachment deduplication.
  - Flexible scheduling intervals (15m, 30m, hourly, twice daily, daily, manual).
  - Process-level transient concurrency locking with automatic stale-lock recovery.
  - Keyword filtering (must-contain / must-exclude) and article age limits.
  - Idempotent existing-post update mode preserving user modifications.
  - Native WordPress post type storage (`wpcri_feed`) with zero data loss on uninstall.
  - Overview tab with real-time ingestion KPIs, visual workflow, and architecture documentation.
  - In-dashboard GitHub updater support for automated release updates.
  - Fully tested across PHP 8.2 and PHP 8.3 with 100% automated test coverage.

---

## License

WPCalibrate RSS Importer is open-source software licensed under the **GNU General Public License v2.0 or later** (GPL-2.0-or-later). See the [LICENSE.txt](LICENSE.txt) file for complete details.

---

## Support & Inquiries

For technical assistance, bug reports, feature requests, or enterprise support:
- **Email:** [support@wpcalibrate.com](mailto:support@wpcalibrate.com)
- **Website:** [https://wpcalibrate.com](https://wpcalibrate.com)
- **Marketplace:** [https://marketplace.wpcalibrate.com/](https://marketplace.wpcalibrate.com/)
