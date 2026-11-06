# Page Content Crawler

WordPress plugin that fetches HTML from a URL, extracts one element by CSS selector, and stores the result and its update time on a selected page.

Requires WordPress 6.4 or newer and PHP 8.1 or newer. License: GPL-2.0-or-later.

## Requirements

- WordPress 6.4+
- PHP 8.1+
- PHP extensions: `dom`, `mbstring`
- [Composer](https://getcomposer.org/)

`symfony/css-selector` is installed through Composer.

## Install

Copy this directory to `wp-content/plugins/page-content-crawler`, then install dependencies and activate the plugin in **Plugins**.

```bash
cd wp-content/plugins/page-content-crawler
composer install
```

`vendor/` is not in git. Activation needs `vendor/autoload.php`.

If PHP, WordPress, a required extension, or the Composer autoloader is missing, activation stops, the plugin deactivates itself, and the failure message is shown. The same message appears as an admin error notice for users who can activate plugins.

## Language

English is the source language. Persian (`fa_IR`) is in `languages/`. WordPress 6.5 and newer loads `page-content-crawler-fa_IR.l10n.php`. WordPress 6.4 loads `page-content-crawler-fa_IR.mo`. The site locale must be `fa_IR`.

## Settings

Users who can `manage_options` open **Settings → Page Content Crawler**.

- `pcc_source_url` — http or https URL to fetch. Empty is allowed.
- `pcc_css_selector` — one CSS selector, up to 300 characters, no HTML.
- `pcc_crawl_interval` — minutes between crawls. `0` disables it. `5` runs every 5 minutes. Maximum is 43200 (30 days).
- `pcc_target_page_id` — page that stores the result. An empty choice, a non-page, or a trashed page is rejected.

A value of 1 or more schedules `pcc_cron_run` at that many minutes. `0` clears it. Deactivation clears it too. WordPress cron runs on site traffic, so the real gap can be longer than the number of minutes.

## Stored data

`PageContentCrawler\Plugin::storeResult()` writes two post meta fields on the page selected in settings:

- `pcc_content` — extracted HTML
- `pcc_updated_at` — site-local MySQL datetime from `current_time('mysql')`

If no storable page is selected, `storeResult()` writes nothing. Deactivation does not delete the meta fields or the settings options.

## What runs today

On load, the plugin loads the `page-content-crawler` text domain and checks the requirements above. Requirement notices are built on `admin_notices`, after the text domain loads on `init`. When the requirements pass, the settings screen is registered.

On deactivation it clears the `pcc_cron_run` cron hook and deletes the `pcc_lock` option.

The saved interval schedules `pcc_cron_run`. Fetching the URL is not hooked to that event yet, so it does not call `storeResult()`.

## License

[GNU General Public License v2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html).
