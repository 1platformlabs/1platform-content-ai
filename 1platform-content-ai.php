<?php

/**
 * Plugin Name: 1Platform Content AI
 * Plugin URI: https://1platform.pro/
 * Description: SaaS client for AI-powered content generation, SEO optimization, and site management. All AI processing happens on 1Platform external servers. Includes free local tools: Table of Contents and Internal Links.
 * Version: 2.3.1
 * Author: 1Platform
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: 1platform-content-ai
 * Requires at least: 5.9
 * Tested up to: 6.9
 * Requires PHP: 7.4
 *
 * @package OnePlatformContentAI
 */

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'includes/helpers/security.php';
require_once plugin_dir_path(__FILE__) . 'includes/helpers/crypto.php';
require_once plugin_dir_path(__FILE__) . 'includes/helpers/asset-version.php';
require_once plugin_dir_path(__FILE__) . 'includes/helpers/site-generation.php';
require_once plugin_dir_path(__FILE__) . 'includes/helpers/ai-generation.php';
require_once plugin_dir_path(__FILE__) . 'includes/providers/UserProvider.php';
require_once plugin_dir_path(__FILE__) . 'includes/providers/WebsiteProvider.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/admin-init-configuration.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/admin-licenses.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/admin-adsense-injector.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/admin-content-generator.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/admin-apps.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/admin-ai-site-generator.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/admin-job-monitor.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/admin-billing.php';

require_once plugin_dir_path(__FILE__) . 'includes/services/toc/HeadingParser.php';
require_once plugin_dir_path(__FILE__) . 'includes/services/toc/AnchorGenerator.php';
require_once plugin_dir_path(__FILE__) . 'includes/services/toc/TocBuilder.php';
require_once plugin_dir_path(__FILE__) . 'includes/services/toc/ContentInjector.php';
require_once plugin_dir_path(__FILE__) . 'includes/services/toc/TocConfiguration.php';
require_once plugin_dir_path(__FILE__) . 'includes/services/toc/TocGenerator.php';
require_once plugin_dir_path(__FILE__) . 'includes/services/toc/TocWordPressIntegration.php';
require_once plugin_dir_path(__FILE__) . 'includes/services/toc/TocFactory.php';
require_once plugin_dir_path(__FILE__) . 'includes/services/internal-links/InternalLinksWordPressIntegration.php';
require_once plugin_dir_path(__FILE__) . 'includes/cron/job-processor-cron.php';
require_once plugin_dir_path(__FILE__) . 'includes/header.php';

require_once plugin_dir_path(__FILE__) . 'includes/database/migrations/CreateKeywordsTable.php';
require_once plugin_dir_path(__FILE__) . 'includes/database/migrations/CreateAPILogsTable.php';
require_once plugin_dir_path(__FILE__) . 'includes/database/migrations/CreateJobsTable.php';
require_once plugin_dir_path(__FILE__) . 'includes/database/migrations/UpdateKeywordsTableStatus.php';
require_once plugin_dir_path(__FILE__) . 'includes/database/migrations/CreateInternalLinksTable.php';

function contai_activate_plugin() {
    try {
        $keywords_migration = new ContaiCreateKeywordsTable();
        $keywords_migration->up();
    } catch (Exception $e) {
        contai_log("ContaiCreateKeywordsTable migration error: " . $e->getMessage());
    }

    try {
        $api_logs_migration = new ContaiCreateAPILogsTable();
        $api_logs_migration->up();
    } catch (Exception $e) {
        contai_log("ContaiCreateAPILogsTable migration error: " . $e->getMessage());
    }

    try {
        $jobs_migration = new ContaiCreateJobsTable();
        $jobs_migration->up();
    } catch (Exception $e) {
        contai_log("ContaiCreateJobsTable migration error: " . $e->getMessage());
    }

    try {
        $update_keywords_status = new ContaiUpdateKeywordsTableStatus();
        $update_keywords_status->up();
    } catch (Exception $e) {
        contai_log("ContaiUpdateKeywordsTableStatus migration error: " . $e->getMessage());
    }

    try {
        $internal_links_migration = new ContaiCreateInternalLinksTable();
        $internal_links_migration->up();
    } catch (Exception $e) {
        contai_log("ContaiCreateInternalLinksTable migration error: " . $e->getMessage());
    }

    try {
        contai_register_job_processor_cron();
    } catch (Exception $e) {
        contai_log("Cron registration error: " . $e->getMessage());
    }

    // Set site hardening defaults for existing installs (opt-in for new installs)
    add_option('contai_disable_feeds', '1');
    add_option('contai_disable_author_pages', '1');
    add_option('contai_redirect_404', '1');
}
register_activation_hook(__FILE__, 'contai_activate_plugin');

register_deactivation_hook(__FILE__, 'contai_unregister_job_processor_cron');

$toc_integration = ContaiTocFactory::create();
$toc_integration->register();

add_action('plugins_loaded', function() {
    $internal_links_integration = new ContaiInternalLinksWordPressIntegration();
    $internal_links_integration->register();
});

/**
 * Check if the license key option exists and is valid
 *
 * @return bool True if license key is present and non-empty, false otherwise
 */
function contai_has_license_key() {
    $license_key = get_option('contai_api_key', '');

    if (empty($license_key)) {
        return false;
    }

    if (is_string($license_key)) {
        $trimmed = trim($license_key);
        return !empty($trimmed);
    }

    return false;
}

/**
 * Register all admin menus unconditionally.
 *
 * All menus are always accessible regardless of API key status.
 * When the API key is not configured, SaaS-dependent pages display
 * a connection CTA instead of blocking access.
 * Local features (TOC, Internal Links) work without any API key.
 * This satisfies WordPress.org Guideline 5 (no trialware hard-gating).
 */
function contai_register_admin_menus() {
    add_menu_page( '1Platform Content AI', '1Platform Content AI', 'manage_options', 'contai-website-settings', 'contai_website_settings_page', 'dashicons-admin-comments', 90 );
    add_submenu_page( 'contai-website-settings', 'Settings', 'Settings', 'manage_options', 'contai-website-settings', 'contai_website_settings_page' );
    add_submenu_page( 'contai-website-settings', 'Content', 'Content', 'manage_options', 'contai-content-generator', 'contai_content_generator_page' );
    add_submenu_page( 'contai-website-settings', 'Tools', 'Tools', 'manage_options', 'contai-apps', 'contai_apps_page' );
    add_submenu_page( 'contai-website-settings', 'Site Wizard', 'Site Wizard', 'manage_options', 'contai-ai-site-generator', 'contai_ai_site_generator_page' );
    add_submenu_page( 'contai-website-settings', 'Jobs', 'Jobs', 'manage_options', 'contai-job-monitor', 'contai_render_job_monitor_page' );
    add_submenu_page( 'contai-website-settings', 'Billing', 'Billing', 'manage_options', 'contai-billing', 'contai_billing_page' );
    add_submenu_page( 'contai-website-settings', 'License', 'License', 'manage_options', 'contai-licenses', 'contai_licenses_page' );
}
add_action( 'admin_menu', 'contai_register_admin_menus' );

/**
 * Render a connection-required notice when the API key is not configured.
 *
 * SaaS-dependent pages call this function to display a CTA instead of
 * blocking access with redirects. Returns true if notice was rendered
 * (caller should skip page content).
 *
 * @return bool True if the notice was rendered.
 */
function contai_render_connection_required_notice() {
    if ( contai_has_license_key() ) {
        return false;
    }

    $license_url = admin_url( 'admin.php?page=contai-licenses' );
    ?>
    <div class="wrap">
        <div class="contai-connection-notice" style="padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-left: 4px solid #ffb900; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h2 style="margin-top: 0;">
                <span class="dashicons dashicons-admin-network" style="font-size: 24px; margin-right: 8px;"></span>
                <?php esc_html_e( 'Connect to 1Platform Content AI', '1platform-content-ai' ); ?>
            </h2>
            <p style="font-size: 14px; max-width: 600px;">
                <?php esc_html_e( 'This feature requires an active connection to the 1Platform Content AI service. Enter your API key to enable AI-powered content generation, keyword extraction, and other cloud features.', '1platform-content-ai' ); ?>
            </p>
            <p style="font-size: 13px; color: #666; max-width: 600px;">
                <?php esc_html_e( 'Local tools like Table of Contents and Internal Links work without an API key.', '1platform-content-ai' ); ?>
            </p>
            <p>
                <a href="<?php echo esc_url( $license_url ); ?>" class="button button-primary button-hero">
                    <span class="dashicons dashicons-admin-network" style="margin-top: 5px;"></span>
                    <?php esc_html_e( 'Enter API Key', '1platform-content-ai' ); ?>
                </a>
                <a href="https://1platform.pro" target="_blank" rel="noopener noreferrer" class="button button-secondary button-hero" style="margin-left: 10px;">
                    <?php esc_html_e( 'Get an API Key', '1platform-content-ai' ); ?>
                </a>
            </p>
        </div>
    </div>
    <?php
    return true;
}

/**
 * Display admin notices for authentication token errors on plugin pages.
 */
function contai_display_auth_error_notices(): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'contai') === false) {
        return;
    }

    require_once plugin_dir_path(__FILE__) . 'includes/services/api/OnePlatformAuthService.php';

    $app_error = ContaiOnePlatformAuthService::getAppTokenError();
    if ($app_error !== null) {
        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
            esc_html__('Content AI Authentication Error:', '1platform-content-ai'),
            esc_html($app_error)
        );
    }

    $user_error = ContaiOnePlatformAuthService::getUserTokenError();
    if ($user_error !== null) {
        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
            esc_html__('Content AI License Error:', '1platform-content-ai'),
            esc_html($user_error),
            esc_url(admin_url('admin.php?page=contai-licenses')),
            esc_html__('Check your API key settings.', '1platform-content-ai')
        );
    }
}
add_action('admin_notices', 'contai_display_auth_error_notices');
