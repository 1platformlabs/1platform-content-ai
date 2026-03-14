<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/licenses/WPContentAILicensePanel.php';

function contai_handle_license_form_submission() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only, checking current admin page.
    if ( ! isset( $_GET['page'] ) || sanitize_key( wp_unslash( $_GET['page'] ) ) !== 'contai-licenses' ) {
        return;
    }

    $wpContentAIPanel = new WPContentAILicensePanel();
    $wpContentAIPanel->handleFormSubmissionEarly();
}
add_action('admin_init', 'contai_handle_license_form_submission');

function contai_enqueue_licenses_styles() {
    $screen = get_current_screen();

    if ($screen && strpos($screen->id, 'contai-licenses') !== false) {
        contai_enqueue_style_with_version(
            'contai-content-generator-base',
            plugin_dir_url(__FILE__) . 'content-generator/assets/css/base.css',
            array()
        );

        contai_enqueue_style_with_version(
            'contai-admin-licenses',
            plugin_dir_url(__FILE__) . 'assets/css/admin-licenses.css',
            array('contai-content-generator-base')
        );
    }
}
add_action('admin_enqueue_scripts', 'contai_enqueue_licenses_styles', 20);

function contai_licenses_page() {
    ?>
    <div class="wrap contai-settings-wrap">
        <h1>
            <span class="dashicons dashicons-lock"></span>
            <?php esc_html_e('License', '1platform-content-ai'); ?>
        </h1>

        <div class="contai-page-description">
            <p>
                <?php esc_html_e('Manage your license and connect with Content AI services.', '1platform-content-ai'); ?>
            </p>
        </div>

        <?php
        $wpContentAIPanel = new WPContentAILicensePanel();
        $wpContentAIPanel->render();
        ?>
    </div>
    <?php
}
