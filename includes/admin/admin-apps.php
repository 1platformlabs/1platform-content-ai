<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/apps/components/AppsLayout.php';
require_once __DIR__ . '/apps/components/AppsSections.php';
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

function contai_enqueue_apps_scripts()
{
    $screen = get_current_screen();

    if ( ! $screen || strpos( $screen->id, 'contai-apps' ) === false ) {
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only section navigation parameter.
    $section = sanitize_key( $_GET['section'] ?? 'toc' );

    $css_base_url    = plugin_dir_url( __FILE__ ) . 'apps/assets/css/';
    $section_css_map = array(
        'internal-links' => 'internal-links.css',
        'search-console' => 'search-console.css',
        'publisuites'    => 'publisuites.css',
        'ads-manager'    => 'publisher-panel.css',
    );

    if ( isset( $section_css_map[ $section ] ) ) {
        contai_enqueue_style_with_version(
            "contai-apps-{$section}",
            $css_base_url . $section_css_map[ $section ],
            array( 'contai-tokens' )
        );
    }

    if ( $section === 'ads-manager' ) {
        contai_enqueue_style_with_version(
            'contai-adsense-account',
            $css_base_url . 'adsense-account.css',
            array( 'contai-tokens' )
        );
    }

    $section_js_map = array(
        'ads-manager' => 'publisher-panel.js',
    );

    if ( isset( $section_js_map[ $section ] ) ) {
        $js_base_url = plugin_dir_url( __FILE__ ) . 'apps/assets/js/';
        contai_enqueue_script_with_version(
            "contai-apps-{$section}",
            $js_base_url . $section_js_map[ $section ],
            array(),
            true
        );
    }

    if ( $section === 'ads-manager' ) {
        $js_base_url = plugin_dir_url( __FILE__ ) . 'apps/assets/js/';
        contai_enqueue_script_with_version(
            'contai-adsense-account',
            $js_base_url . 'adsense-account.js',
            array(),
            true
        );
        wp_localize_script( 'contai-adsense-account', 'contaiAdsense', array(
            'restUrl' => esc_url_raw( rest_url( 'contai/v1/adsense/' ) ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
        ) );
    }
}
add_action( 'admin_enqueue_scripts', 'contai_enqueue_apps_scripts', 20 );

function contai_apps_page()
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only section navigation parameter.
    $section = sanitize_key($_GET['section'] ?? 'apps');

    $section = ContaiAppsSections::resolve($section);

    $layout = new ContaiAppsLayout($section);
    $layout->render_header();

    $panel = ContaiAppsSections::createPanel($section);
    $layout->render_page_title(...ContaiAppsSections::header($section));
    $panel->render();

    $layout->render_footer();
}
