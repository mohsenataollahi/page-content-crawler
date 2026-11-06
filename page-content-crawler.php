<?php
/**
 * Plugin Name: Page Content Crawler
 * Description: Fetches HTML from a URL, extracts one CSS selector, and stores the result and its update time on a selected page.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Mohsen Ataollahi
 * Author URI: https://github.com/mohsenataollahi
 * Text Domain: page-content-crawler
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package PageContentCrawler
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('PCC_VERSION', '1.0.0');
define('PCC_FILE', __FILE__);
define('PCC_PATH', plugin_dir_path(__FILE__));
define('PCC_URL', plugin_dir_url(__FILE__));

require_once PCC_PATH . 'includes/class-plugin.php';
require_once PCC_PATH . 'includes/class-settings.php';

if (is_readable(PCC_PATH . 'vendor/autoload.php')) {
    require_once PCC_PATH . 'vendor/autoload.php';
}

register_activation_hook(PCC_FILE, [PageContentCrawler\Plugin::class, 'activate']);
register_deactivation_hook(PCC_FILE, [PageContentCrawler\Plugin::class, 'deactivate']);
add_action('plugins_loaded', [PageContentCrawler\Plugin::class, 'boot']);
