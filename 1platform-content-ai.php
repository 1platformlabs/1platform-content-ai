<?php

/**
 * Plugin Name: 1Platform Content AI
 * Plugin URI: https://1platform.pro/
 * Description: SaaS client for AI-powered content generation, SEO optimization, and site management. All AI processing happens on 1Platform external servers. Includes free local tools: Table of Contents and Internal Links.
 * Version: 2.18.0
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
require_once plugin_dir_path(__FILE__) . 'includes/admin/panels/ContaiLogsPanel.php';
require_once plugin_dir_path(__FILE__) . 'includes/services/logs/ContaiLogsService.php';
require_once plugin_dir_path(__FILE__) . 'includes/adapters/ContaiLogsAdapter.php';
require_once plugin_dir_path(__FILE__) . 'includes/helpers/ContaiNoticeHelper.php';
require_once plugin_dir_path(__FILE__) . 'includes/services/logs/ContaiClientLogReporter.php';

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

// Agent domain
require_once plugin_dir_path( __FILE__ ) . 'includes/services/agents/ContaiAgentEndpoints.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/services/agents/ContaiAgentApiService.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/services/agents/ContaiAgentSettingsService.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/services/agents/ContaiAgentActionHandler.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/services/agents/ContaiAgentSyncService.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/services/agents/ContaiAgentRestController.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/admin/agents/ContaiAgentsAdminPage.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/cron/agent-actions-cron.php';

// SEO domain
require_once plugin_dir_path(__FILE__) . 'includes/services/seo/SeoHeadService.php';
$contai_seo_head = new ContaiSeoHeadService();
$contai_seo_head->register();

// Analytics domain
require_once plugin_dir_path(__FILE__) . 'includes/analytics/class-analytics-tag.php';
require_once plugin_dir_path(__FILE__) . 'includes/analytics/class-analytics-server.php';

require_once plugin_dir_path(__FILE__) . 'includes/database/MigrationRunner.php';
require_once plugin_dir_path(__FILE__) . 'includes/database/migrations/CreateKeywordsTable.php';
require_once plugin_dir_path(__FILE__) . 'includes/database/migrations/CreateAPILogsTable.php';
require_once plugin_dir_path(__FILE__) . 'includes/database/migrations/CreateJobsTable.php';
require_once plugin_dir_path(__FILE__) . 'includes/database/migrations/UpdateKeywordsTableStatus.php';
require_once plugin_dir_path(__FILE__) . 'includes/database/migrations/CreateInternalLinksTable.php';
require_once plugin_dir_path(__FILE__) . 'includes/database/migrations/BackfillAnalyticsMeta.php';

/**
 * Build the migration runner with all registered migrations.
 *
 * Each migration is assigned a sequential version number.
 * New migrations MUST be appended at the end with the next version number.
 */
function contai_build_migration_runner(): ContaiMigrationRunner {
    $runner = new ContaiMigrationRunner();

    $runner->register(1, new ContaiCreateKeywordsTable());
    $runner->register(2, new ContaiCreateAPILogsTable());
    $runner->register(3, new ContaiCreateJobsTable());
    $runner->register(4, new ContaiUpdateKeywordsTableStatus());
    $runner->register(5, new ContaiCreateInternalLinksTable());
    $runner->register(6, new ContaiBackfillAnalyticsMeta());

    return $runner;
}

function contai_activate_plugin() {
    $runner = contai_build_migration_runner();
    $result = $runner->run();

    if (!$result['success']) {
        contai_log('Migration batch failed: ' . $result['message']);
    }

    try {
        contai_register_job_processor_cron();
    } catch (Exception $e) {
        contai_log("Cron registration error: " . $e->getMessage());
    }

    try {
        contai_register_agent_actions_cron();
    } catch (Exception $e) {
        contai_log("Agent cron registration error: " . $e->getMessage());
    }

    // Set site hardening defaults for existing installs (opt-in for new installs)
    add_option('contai_disable_feeds', '1');
    add_option('contai_disable_author_pages', '1');
    add_option('contai_redirect_404', '1');
}
register_activation_hook(__FILE__, 'contai_activate_plugin');

register_deactivation_hook(__FILE__, 'contai_unregister_job_processor_cron');
register_deactivation_hook( __FILE__, 'contai_unregister_agent_actions_cron' );

$toc_integration = ContaiTocFactory::create();
$toc_integration->register();

add_action('plugins_loaded', function() {
    $internal_links_integration = new ContaiInternalLinksWordPressIntegration();
    $internal_links_integration->register();

    // Analytics: GA4 tag injection + server-side events
    if (get_option('1platform_ga4_measurement_id', '')) {
        $analytics_tag = new OnePlatform_Analytics_Tag();
        $analytics_tag->init();

        $analytics_server = new OnePlatform_Analytics_Server();
        $analytics_server->init();
    }
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
    add_submenu_page( 'contai-website-settings', 'Logs', 'Logs', 'manage_options', 'contai-logs', 'contai_logs_page' );
    add_submenu_page( 'contai-website-settings', 'Agents', 'Agents', 'manage_options', 'contai-agents', 'contai_agents_page' );
    add_submenu_page( 'contai-website-settings', 'License', 'License', 'manage_options', 'contai-licenses', 'contai_licenses_page' );

}
add_action( 'admin_menu', 'contai_register_admin_menus' );

add_action( 'rest_api_init', function() {
    $controller = new ContaiAgentRestController();
    $controller->register_routes();

    require_once plugin_dir_path( __FILE__ ) . 'includes/services/adsense/ContaiAdSenseRestController.php';
    $adsense_controller = new ContaiAdSenseRestController();
    $adsense_controller->register_routes();
} );

/**
 * Render the Agents admin page.
 */
function contai_agents_page() {
    ContaiAgentsAdminPage::render();
}

add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( strpos( $hook, 'contai-agents' ) === false ) {
        return;
    }
    wp_enqueue_style( 'contai-agents-admin', plugin_dir_url( __FILE__ ) . 'includes/admin/agents/contai-agents-admin.css', array(), '2.3.6' );
    wp_enqueue_script( 'contai-agents-admin', plugin_dir_url( __FILE__ ) . 'includes/admin/agents/contai-agents-admin.js', array(), '2.3.6', true );
    wp_localize_script( 'contai-agents-admin', 'contaiAgents', array(
        'restUrl'  => rest_url( 'contai/v1/' ),
        'nonce'    => wp_create_nonce( 'wp_rest' ),
        'settings' => ContaiAgentSettingsService::getAllSettings(),
        'adminUrl' => admin_url( 'admin.php?page=contai-agents' ),
    ) );
} );

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
 * Render the Logs admin page.
 */
function contai_logs_page() {
    $panel = new ContaiLogsPanel();
    $panel->render();
}

/**
 * Sync buffered client logs to the API on shutdown.
 */
add_action('shutdown', function() {
    if (is_admin() && ContaiClientLogReporter::getBufferCount() > 0) {
        ContaiClientLogReporter::syncBuffer();
    }
});

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
    require_once plugin_dir_path(__FILE__) . 'includes/services/config/Config.php';

    // If tokens are currently valid, clear any stale error notices
    // left over from a previous transient failure.
    $authService = ContaiOnePlatformAuthService::create();
    if ($authService->validateToken()) {
        $authService->clearErrors();
        return;
    }

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

/**
 * Display admin notice when database migrations have failed.
 */
function contai_display_migration_error_notice(): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'contai') === false) {
        return;
    }

    require_once plugin_dir_path(__FILE__) . 'includes/database/MigrationRunner.php';

    $error = ContaiMigrationRunner::getError();
    if ($error === null) {
        return;
    }

    printf(
        '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
        esc_html__('Content AI Database Error:', '1platform-content-ai'),
        esc_html($error)
    );
}
add_action('admin_notices', 'contai_display_migration_error_notice');

/**
 * Display AdSense policy issue notices.
 */
function contai_display_adsense_policy_notice() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $policy_count = get_transient( 'contai_adsense_policy_count' );
    if ( $policy_count === false || (int) $policy_count === 0 ) {
        return;
    }

    printf(
        '<div class="notice notice-warning"><p>%s</p></div>',
        esc_html( sprintf(
            /* translators: %d: number of policy issues */
            __( '%d AdSense policy issue(s) detected. Review in Tools > Ads Manager.', '1platform-content-ai' ),
            (int) $policy_count
        ) )
    );
}
add_action( 'admin_notices', 'contai_display_adsense_policy_notice' );
