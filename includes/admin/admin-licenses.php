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

function contai_licenses_page() {
    ?>
    <div class="wrap contai-app contai-page">
        <div class="contai-page-header">
            <div class="contai-page-header-row">
                <div>
                    <h1 class="contai-page-title">
                        <span class="contai-tile" aria-hidden="true">
                            <span class="dashicons dashicons-lock"></span>
                        </span>
                        <?php esc_html_e( 'License', '1platform-content-ai' ); ?>
                    </h1>
                    <p class="contai-page-subtitle">
                        <?php esc_html_e( 'Manage your license and connect with Content AI services.', '1platform-content-ai' ); ?>
                    </p>
                </div>
            </div>
        </div>

        <?php
        $wpContentAIPanel = new WPContentAILicensePanel();
        $wpContentAIPanel->render();
        ?>
    </div>
    <?php
}
