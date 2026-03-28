<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/apps/components/AppsLayout.php';
require_once __DIR__ . '/apps/panels/AppsPanel.php';
require_once __DIR__ . '/apps/panels/TocSettingsPanel.php';
require_once __DIR__ . '/apps/panels/InternalLinksPanel.php';
require_once __DIR__ . '/apps/panels/SearchConsolePanel.php';
require_once __DIR__ . '/apps/panels/PublisuitesPanel.php';
require_once __DIR__ . '/apps/panels/AdsManagerPanel.php';
require_once __DIR__ . '/apps/panels/AnalyticsPanel.php';
require_once __DIR__ . '/apps/handlers/SearchConsoleFormHandler.php';
require_once __DIR__ . '/apps/handlers/InternalLinksQueueHandler.php';
require_once __DIR__ . '/apps/handlers/PublisuitesFormHandler.php';
require_once __DIR__ . '/apps/handlers/AnalyticsFormHandler.php';

new ContaiAnalyticsFormHandler();

require_once __DIR__ . '/../services/toc/HeadingParser.php';
require_once __DIR__ . '/../services/toc/AnchorGenerator.php';
require_once __DIR__ . '/../services/toc/TocBuilder.php';
require_once __DIR__ . '/../services/toc/ContentInjector.php';
require_once __DIR__ . '/../services/toc/TocConfiguration.php';
require_once __DIR__ . '/../services/toc/TocGenerator.php';
require_once __DIR__ . '/../services/toc/TocWordPressIntegration.php';
require_once __DIR__ . '/../services/toc/TocFactory.php';

function contai_handle_search_console_form_submission()
{
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Page routing check; nonce verified in handler.
    if ( ! isset( $_GET['page'] ) || sanitize_key( wp_unslash( $_GET['page'] ) ) !== 'contai-apps' ) {
        return;
    }

    if ( ! isset( $_GET['section'] ) || sanitize_key( wp_unslash( $_GET['section'] ) ) !== 'search-console' ) {
        return;
    }
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

    $handler = new ContaiSearchConsoleFormHandler();
    $handler->handleRequest();
}
add_action('admin_init', 'contai_handle_search_console_form_submission');

function contai_handle_internal_links_queue_submission()
{
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Page routing check; nonce verified in handler.
    if ( ! isset( $_GET['page'] ) || sanitize_key( wp_unslash( $_GET['page'] ) ) !== 'contai-apps' ) {
        return;
    }

    if ( ! isset( $_GET['section'] ) || sanitize_key( wp_unslash( $_GET['section'] ) ) !== 'internal-links' ) {
        return;
    }
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

    $handler = new ContaiInternalLinksQueueHandler();
    $handler->handleRequest();
}
add_action('admin_init', 'contai_handle_internal_links_queue_submission');

function contai_handle_publisuites_form_submission()
{
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Page routing check; nonce verified in handler.
    if ( ! isset( $_GET['page'] ) || sanitize_key( wp_unslash( $_GET['page'] ) ) !== 'contai-apps' ) {
        return;
    }

    if ( ! isset( $_GET['section'] ) || sanitize_key( wp_unslash( $_GET['section'] ) ) !== 'publisuites' ) {
        return;
    }
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

    $handler = new ContaiPublisuitesFormHandler();
    $handler->handleRequest();
}
add_action('admin_init', 'contai_handle_publisuites_form_submission');

function contai_enqueue_apps_styles()
{
    $screen = get_current_screen();

    if ($screen && strpos($screen->id, 'contai-apps') !== false) {
        // Enqueue content-generator base CSS first (contains CSS variables)
        $content_gen_base_url = plugin_dir_url(__FILE__) . 'content-generator/assets/css/base.css';
        contai_enqueue_style_with_version(
            'contai-content-generator-base',
            $content_gen_base_url,
            []
        );

        // Enqueue apps base CSS with dependency on content-generator base
        $css_base_url = plugin_dir_url(__FILE__) . 'apps/assets/css/';
        contai_enqueue_style_with_version(
            'contai-apps-base',
            $css_base_url . 'base.css',
            ['contai-content-generator-base']
        );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only section navigation parameter.
        $section = sanitize_key($_GET['section'] ?? 'toc');
        $section_css_map = [
            'toc' => 'toc.css',
            'internal-links' => 'internal-links.css',
            'search-console' => 'search-console.css',
            'publisuites' => 'publisuites.css',
            'ads-manager' => 'publisher-panel.css',
        ];

        if (isset($section_css_map[$section])) {
            contai_enqueue_style_with_version(
                "contai-apps-{$section}",
                $css_base_url . $section_css_map[$section],
                ['contai-apps-base']
            );
        }

        $section_js_map = [
            'ads-manager' => 'publisher-panel.js',
        ];

        if (isset($section_js_map[$section])) {
            $js_base_url = plugin_dir_url(__FILE__) . 'apps/assets/js/';
            contai_enqueue_script_with_version(
                "contai-apps-{$section}",
                $js_base_url . $section_js_map[$section],
                [],
                true
            );
        }
    }
}
add_action('admin_enqueue_scripts', 'contai_enqueue_apps_styles', 20);

function contai_apps_page()
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only section navigation parameter.
    $section = sanitize_key($_GET['section'] ?? 'apps');
    $valid_sections = ['toc', 'internal-links', 'search-console', 'publisuites', 'ads-manager', 'analytics'];

    if (!in_array($section, $valid_sections, true)) {
        $section = 'apps';
    }

    $layout = new ContaiAppsLayout($section);
    $layout->render_header();

    switch ($section) {
        case 'toc':
            $config = new ContaiTocConfiguration();
            $panel = new ContaiTocSettingsPanel($config);
            $layout->render_page_title(
                __('Table of Contents Settings', '1platform-content-ai'),
                __('Configure how the table of contents is displayed on your site', '1platform-content-ai'),
                'dashicons-admin-generic'
            );
            $panel->render();
            break;
        case 'internal-links':
            $panel = new ContaiInternalLinksPanel();
            $layout->render_page_title(
                __('Internal Links', '1platform-content-ai'),
                __('Configure automatic internal linking and view all links between your posts', '1platform-content-ai'),
                'dashicons-admin-links'
            );
            $panel->render();
            break;
        case 'search-console':
            $panel = new ContaiSearchConsolePanel();
            $layout->render_page_title(
                __('Search Console', '1platform-content-ai'),
                __('Connect your website to Google Search Console through Content AI', '1platform-content-ai'),
                'dashicons-cloud'
            );
            $panel->render();
            break;
        case 'publisuites':
            $panel = new ContaiPublisuitesPanel();
            $layout->render_page_title(
                __('Publisuites', '1platform-content-ai'),
                __('Connect your website to the marketplace to monetize your content', '1platform-content-ai'),
                'dashicons-money-alt'
            );
            $panel->render();
            break;
        case 'ads-manager':
            $panel = new ContaiAdsManagerPanel();
            $layout->render_page_title(
                __('Ads Manager', '1platform-content-ai'),
                __('Configure AdSense publisher IDs, ads.txt generation, and custom header code', '1platform-content-ai'),
                'dashicons-megaphone'
            );
            $panel->render();
            break;
        case 'analytics':
            $panel = new ContaiAnalyticsPanel();
            $layout->render_page_title(
                __('Google Analytics', '1platform-content-ai'),
                __('Connect Google Analytics GA4 to track content performance and site traffic', '1platform-content-ai'),
                'dashicons-chart-area'
            );
            $panel->render();
            break;
        case 'apps':
        default:
            $panel = new ContaiAppsPanel();
            $layout->render_page_title(
                __('Tools', '1platform-content-ai'),
                __('Overview of all available tools and integrations', '1platform-content-ai'),
                'dashicons-list-view'
            );
            $panel->render();
    }

    $layout->render_footer();
}
