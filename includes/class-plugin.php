<?php
/**
 * Plugin bootstrap.
 *
 * @package PageContentCrawler
 */

declare(strict_types=1);

namespace PageContentCrawler;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Starts the plugin and stops startup when this site cannot run it.
 */
final class Plugin
{
    public const MINIMUM_PHP = '8.1';

    public const MINIMUM_WORDPRESS = '6.4';

    public const CRON_HOOK = 'pcc_cron_run';

    public const LOCK_OPTION = 'pcc_lock';

    public const CONTENT_META_KEY = 'pcc_content';

    public const UPDATED_AT_META_KEY = 'pcc_updated_at';

    public const TARGET_PAGE_OPTION = 'pcc_target_page_id';

    public const SOURCE_URL_OPTION = 'pcc_source_url';

    public const CSS_SELECTOR_OPTION = 'pcc_css_selector';

    public const CRAWL_INTERVAL_OPTION = 'pcc_crawl_interval';

    public const MIN_CRAWL_MINUTES = 1;

    public const MAX_CRAWL_MINUTES = 43200;

    public const TEXT_DOMAIN = 'page-content-crawler';

    /**
     * dom already requires libxml. Checking libxml by name fails on builds that do not report it.
     *
     * @var list<string>
     */
    private const REQUIRED_EXTENSIONS = ['dom', 'mbstring'];

    public static function boot(): void
    {
        add_action('init', [self::class, 'loadTextdomain']);
        add_action('admin_notices', [self::class, 'renderRequirementNotice']);

        if (self::requirementCode() === '') {
            Settings::register();
        }
    }

    public static function activate(bool $networkWide = false): void
    {
        self::loadTextdomain();

        $failure = self::requirementFailure();
        if ($failure === null) {
            return;
        }

        self::haltActivation($failure, $networkWide);
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        delete_option(self::LOCK_OPTION);
    }

    public static function targetPageId(): int
    {
        return absint(get_option(self::TARGET_PAGE_OPTION, 0));
    }

    public static function sourceUrl(): string
    {
        $url = get_option(self::SOURCE_URL_OPTION, '');

        return is_string($url) ? $url : '';
    }

    public static function cssSelector(): string
    {
        $selector = get_option(self::CSS_SELECTOR_OPTION, '');

        return is_string($selector) ? $selector : '';
    }

    public static function crawlIntervalMinutes(): int
    {
        $stored = get_option(self::CRAWL_INTERVAL_OPTION, 0);
        if (is_string($stored) && isset(self::legacyIntervalMinutes()[$stored])) {
            $stored = self::legacyIntervalMinutes()[$stored];
        }

        $minutes = absint($stored);
        if ($minutes < self::MIN_CRAWL_MINUTES || $minutes > self::MAX_CRAWL_MINUTES) {
            return 0;
        }

        return $minutes;
    }

    private static function migrateLegacyCrawlInterval(): void
    {
        $stored = get_option(self::CRAWL_INTERVAL_OPTION, 0);
        if (! is_string($stored) || ! isset(self::legacyIntervalMinutes()[$stored])) {
            return;
        }

        update_option(self::CRAWL_INTERVAL_OPTION, self::legacyIntervalMinutes()[$stored], false);
    }

    /**
     * @return array<string, int>
     */
    private static function legacyIntervalMinutes(): array
    {
        return [
            'hourly' => 60,
            'pcc_six_hours' => 360,
            'twicedaily' => 720,
            'daily' => 1440,
            'pcc_weekly' => 10080,
        ];
    }

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public static function registerCronSchedules(array $schedules): array
    {
        $minutes = self::crawlIntervalMinutes();
        if ($minutes < self::MIN_CRAWL_MINUTES) {
            return $schedules;
        }

        $schedules[self::crawlScheduleName($minutes)] = [
            'interval' => $minutes * MINUTE_IN_SECONDS,
            'display' => self::crawlScheduleDisplay($minutes),
        ];

        return $schedules;
    }

    public static function ensureCrawlSchedule(): void
    {
        self::migrateLegacyCrawlInterval();
        $minutes = self::crawlIntervalMinutes();
        $scheduled = wp_get_scheduled_event(self::CRON_HOOK);

        if ($minutes < self::MIN_CRAWL_MINUTES) {
            if ($scheduled instanceof \stdClass) {
                wp_clear_scheduled_hook(self::CRON_HOOK);
            }

            return;
        }

        $scheduleName = self::crawlScheduleName($minutes);
        $intervalSeconds = $minutes * MINUTE_IN_SECONDS;
        $sameSchedule = $scheduled instanceof \stdClass
            && $scheduled->schedule === $scheduleName
            && (int) $scheduled->interval === $intervalSeconds;

        if ($sameSchedule) {
            return;
        }

        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_schedule_event(time() + $intervalSeconds, $scheduleName, self::CRON_HOOK);
    }

    private static function crawlScheduleName(int $minutes): string
    {
        return 'pcc_' . $minutes . '_minutes';
    }

    private static function crawlScheduleDisplay(int $minutes): string
    {
        if (! did_action('init')) {
            return 'Every ' . $minutes . ' minutes';
        }

        if ($minutes === 1) {
            return __('Every minute', self::TEXT_DOMAIN);
        }

        return sprintf(
            /* translators: %d: number of minutes. */
            __('Every %d minutes', self::TEXT_DOMAIN),
            $minutes
        );
    }

    public static function isStorablePage(int $postId): bool
    {
        if ($postId <= 0 || get_post_type($postId) !== 'page') {
            return false;
        }

        $status = get_post_status($postId);

        return $status !== false && $status !== 'trash';
    }

    public static function storeResult(string $content): void
    {
        $postId = self::targetPageId();
        if (! self::isStorablePage($postId)) {
            return;
        }

        update_post_meta($postId, self::CONTENT_META_KEY, $content);
        update_post_meta($postId, self::UPDATED_AT_META_KEY, current_time('mysql'));
    }

    public static function loadTextdomain(): void
    {
        load_plugin_textdomain(
            self::TEXT_DOMAIN,
            false,
            dirname(plugin_basename(PCC_FILE)) . '/languages'
        );
    }

    public static function requirementFailure(): ?string
    {
        $code = self::requirementCode();
        if ($code === '') {
            return null;
        }

        if ($code === 'php') {
            return sprintf(
                /* translators: %s: minimum PHP version. */
                __('Page Content Crawler requires PHP %s or newer.', self::TEXT_DOMAIN),
                self::MINIMUM_PHP
            );
        }

        if ($code === 'wordpress') {
            return sprintf(
                /* translators: %s: minimum WordPress version. */
                __('Page Content Crawler requires WordPress %s or newer.', self::TEXT_DOMAIN),
                self::MINIMUM_WORDPRESS
            );
        }

        if ($code === 'extensions') {
            return sprintf(
                /* translators: %s: comma-separated PHP extension names. */
                __('Page Content Crawler requires these PHP extensions: %s.', self::TEXT_DOMAIN),
                implode(', ', self::missingExtensions())
            );
        }

        return __(
            'Page Content Crawler is missing the vendor autoloader. Install Composer dependencies before activating it.',
            self::TEXT_DOMAIN
        );
    }

    private static function requirementCode(): string
    {
        if (version_compare(PHP_VERSION, self::MINIMUM_PHP, '<')) {
            return 'php';
        }

        $wordpressVersion = isset($GLOBALS['wp_version']) ? (string) $GLOBALS['wp_version'] : '0';
        if (version_compare($wordpressVersion, self::MINIMUM_WORDPRESS, '<')) {
            return 'wordpress';
        }

        if (self::missingExtensions() !== []) {
            return 'extensions';
        }

        if (! is_readable(self::vendorAutoloadPath())) {
            return 'autoload';
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private static function missingExtensions(): array
    {
        $missing = [];

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (! extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }

        return $missing;
    }

    private static function haltActivation(string $message, bool $networkWide): void
    {
        if (! function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        deactivate_plugins(plugin_basename(PCC_FILE), false, $networkWide);
        wp_die(esc_html($message));
    }

    public static function renderRequirementNotice(): void
    {
        if (! current_user_can('activate_plugins')) {
            return;
        }

        $failure = self::requirementFailure();
        if ($failure === null) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html($failure)
        );
    }

    private static function vendorAutoloadPath(): string
    {
        return PCC_PATH . 'vendor/autoload.php';
    }
}
