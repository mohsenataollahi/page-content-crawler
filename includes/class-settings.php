<?php
/**
 * Settings screen.
 *
 * @package PageContentCrawler
 */

declare(strict_types=1);

namespace PageContentCrawler;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Lets an administrator choose which page stores the crawl result.
 */
final class Settings
{
    private const SETTINGS_GROUP = 'pcc_settings';

    private const PAGE_SLUG = 'pcc-settings';

    private const MAX_URL_LENGTH = 2000;

    private const MAX_SELECTOR_LENGTH = 300;

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addPage']);
        add_action('admin_init', [self::class, 'registerSetting']);
        add_filter('cron_schedules', [Plugin::class, 'registerCronSchedules']);
        add_action('init', [Plugin::class, 'ensureCrawlSchedule']);
    }

    public static function addPage(): void
    {
        add_options_page(
            __('Page Content Crawler', Plugin::TEXT_DOMAIN),
            __('Page Content Crawler', Plugin::TEXT_DOMAIN),
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'renderPage']
        );
    }

    public static function registerSetting(): void
    {
        self::registerOption(Plugin::SOURCE_URL_OPTION, 'string', [self::class, 'sanitizeSourceUrl']);
        self::registerOption(Plugin::CSS_SELECTOR_OPTION, 'string', [self::class, 'sanitizeCssSelector']);
        self::registerOption(Plugin::CRAWL_INTERVAL_OPTION, 'integer', [self::class, 'sanitizeCrawlInterval']);
        self::registerOption(Plugin::TARGET_PAGE_OPTION, 'integer', [self::class, 'sanitizeTargetPageId']);

        add_settings_section(
            'pcc_crawl',
            __('Crawl', Plugin::TEXT_DOMAIN),
            [self::class, 'renderCrawlSection'],
            self::PAGE_SLUG
        );

        self::addField(Plugin::SOURCE_URL_OPTION, __('Source URL', Plugin::TEXT_DOMAIN), 'renderUrlField', 'pcc_crawl');
        self::addField(Plugin::CSS_SELECTOR_OPTION, __('CSS selector', Plugin::TEXT_DOMAIN), 'renderSelectorField', 'pcc_crawl');
        self::addField(Plugin::CRAWL_INTERVAL_OPTION, __('Crawl interval', Plugin::TEXT_DOMAIN), 'renderIntervalField', 'pcc_crawl');

        add_settings_section(
            'pcc_storage',
            __('Storage', Plugin::TEXT_DOMAIN),
            [self::class, 'renderStorageSection'],
            self::PAGE_SLUG
        );

        self::addField(Plugin::TARGET_PAGE_OPTION, __('Target page', Plugin::TEXT_DOMAIN), 'renderPageField', 'pcc_storage');
    }

    /**
     * @param callable(mixed): (int|string) $sanitize
     */
    private static function registerOption(string $option, string $type, callable $sanitize): void
    {
        register_setting(
            self::SETTINGS_GROUP,
            $option,
            [
                'type' => $type,
                'sanitize_callback' => $sanitize,
                'default' => $type === 'integer' ? 0 : '',
            ]
        );
    }

    private static function addField(string $option, string $label, string $method, string $section): void
    {
        add_settings_field(
            $option,
            $label,
            [self::class, $method],
            self::PAGE_SLUG,
            $section,
            ['label_for' => $option]
        );
    }

    public static function renderPage(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';
        settings_errors();
        echo '<form action="options.php" method="post">';
        settings_fields(self::SETTINGS_GROUP);
        do_settings_sections(self::PAGE_SLUG);
        submit_button();
        echo '</form></div>';
    }

    public static function renderCrawlSection(): void
    {
        echo '<p>' . esc_html__(
            'Set the URL to fetch, the element to keep, and how often the crawl runs.',
            Plugin::TEXT_DOMAIN
        ) . '</p>';
    }

    public static function renderUrlField(): void
    {
        printf(
            '<input type="url" class="regular-text" name="%1$s" id="%1$s" value="%2$s" maxlength="%3$d" />',
            esc_attr(Plugin::SOURCE_URL_OPTION),
            esc_attr(Plugin::sourceUrl()),
            self::MAX_URL_LENGTH
        );
    }

    public static function renderSelectorField(): void
    {
        printf(
            '<input type="text" class="regular-text code" name="%1$s" id="%1$s" value="%2$s" maxlength="%3$d" placeholder="%4$s" />',
            esc_attr(Plugin::CSS_SELECTOR_OPTION),
            esc_attr(Plugin::cssSelector()),
            self::MAX_SELECTOR_LENGTH,
            esc_attr('article .entry-content')
        );
    }

    public static function renderIntervalField(): void
    {
        printf(
            '<input type="number" class="small-text" name="%1$s" id="%1$s" value="%2$d" min="0" max="%3$d" step="1" />',
            esc_attr(Plugin::CRAWL_INTERVAL_OPTION),
            Plugin::crawlIntervalMinutes(),
            Plugin::MAX_CRAWL_MINUTES
        );
        echo '<p class="description">' . esc_html__(
            'Number of minutes between crawls. 0 turns the crawl off. 5 runs every 5 minutes.',
            Plugin::TEXT_DOMAIN
        ) . '</p>';
    }

    public static function sanitizeSourceUrl(mixed $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }

        if (strlen($raw) > self::MAX_URL_LENGTH) {
            self::rejectSourceUrl(sprintf(
                /* translators: %d: maximum number of characters. */
                __('Enter a URL of %d characters or fewer.', Plugin::TEXT_DOMAIN),
                self::MAX_URL_LENGTH
            ));

            return Plugin::sourceUrl();
        }

        $url = esc_url_raw($raw, ['http', 'https']);
        if (! self::isHttpUrl($url)) {
            self::rejectSourceUrl(__('Enter a valid http or https URL.', Plugin::TEXT_DOMAIN));

            return Plugin::sourceUrl();
        }

        return $url;
    }

    public static function sanitizeCssSelector(mixed $value): string
    {
        $selector = trim(str_replace(["\r", "\n", "\0"], '', (string) $value));
        if (str_contains($selector, '<')) {
            add_settings_error(
                Plugin::CSS_SELECTOR_OPTION,
                'pcc_invalid_selector',
                __('Enter a CSS selector without HTML.', Plugin::TEXT_DOMAIN),
                'error'
            );

            return Plugin::cssSelector();
        }

        if (mb_strlen($selector) > self::MAX_SELECTOR_LENGTH) {
            add_settings_error(
                Plugin::CSS_SELECTOR_OPTION,
                'pcc_invalid_selector',
                sprintf(
                    /* translators: %d: maximum number of characters. */
                    __('Enter a CSS selector of %d characters or fewer.', Plugin::TEXT_DOMAIN),
                    self::MAX_SELECTOR_LENGTH
                ),
                'error'
            );

            return Plugin::cssSelector();
        }

        return $selector;
    }

    public static function sanitizeCrawlInterval(mixed $value): int
    {
        $raw = is_int($value) ? (string) $value : trim((string) $value);
        if ($raw === '') {
            return 0;
        }

        $maximumDigits = strlen((string) Plugin::MAX_CRAWL_MINUTES);
        $valid = $raw !== '' && preg_match('/^\d+$/', $raw) === 1 && strlen($raw) <= $maximumDigits;
        $minutes = $valid ? (int) $raw : -1;

        if ($minutes >= 0 && $minutes <= Plugin::MAX_CRAWL_MINUTES) {
            return $minutes;
        }

        add_settings_error(
            Plugin::CRAWL_INTERVAL_OPTION,
            'pcc_invalid_interval',
            sprintf(
                /* translators: %d: maximum number of minutes. */
                __('Enter a whole number of minutes from 0 to %d.', Plugin::TEXT_DOMAIN),
                Plugin::MAX_CRAWL_MINUTES
            ),
            'error'
        );

        return Plugin::crawlIntervalMinutes();
    }

    public static function renderStorageSection(): void
    {
        echo '<p>' . esc_html__(
            'Choose the page that stores the extracted content and its update time.',
            Plugin::TEXT_DOMAIN
        ) . '</p>';
    }

    public static function renderPageField(): void
    {
        $dropdown = wp_dropdown_pages([
            'echo' => 0,
            'name' => Plugin::TARGET_PAGE_OPTION,
            'id' => Plugin::TARGET_PAGE_OPTION,
            'selected' => Plugin::targetPageId(),
            'show_option_none' => esc_html__('— Select a page —', Plugin::TEXT_DOMAIN),
            'option_none_value' => '0',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        ]);

        if (is_string($dropdown) && $dropdown !== '') {
            echo $dropdown;

            return;
        }

        echo '<select name="' . esc_attr(Plugin::TARGET_PAGE_OPTION) . '" id="' . esc_attr(Plugin::TARGET_PAGE_OPTION) . '">';
        echo '<option value="0">' . esc_html__('— Select a page —', Plugin::TEXT_DOMAIN) . '</option>';
        echo '</select>';
        echo '<p class="description">' . esc_html__(
            'No pages are available yet.',
            Plugin::TEXT_DOMAIN
        ) . '</p>';
    }

    public static function sanitizeTargetPageId(mixed $value): int
    {
        $pageId = absint($value);
        if ($pageId === 0 || Plugin::isStorablePage($pageId)) {
            return $pageId;
        }

        add_settings_error(
            Plugin::TARGET_PAGE_OPTION,
            'pcc_invalid_page',
            __('Select a page that is not in the trash.', Plugin::TEXT_DOMAIN),
            'error'
        );

        $current = Plugin::targetPageId();

        return Plugin::isStorablePage($current) ? $current : 0;
    }

    private static function isHttpUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);

        return $scheme === 'http' || $scheme === 'https';
    }

    private static function rejectSourceUrl(string $message): void
    {
        add_settings_error(Plugin::SOURCE_URL_OPTION, 'pcc_invalid_url', $message, 'error');
    }
}
