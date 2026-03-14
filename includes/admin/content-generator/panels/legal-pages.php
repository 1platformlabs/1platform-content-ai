<?php

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . '../helpers/legal-pages-helper.php';

class ContaiLegalPagesPanel {

    private array $legal_info;
    private ?array $api_generation_result = null;
    private bool $legal_info_saved = false;
    private bool $cookie_settings_saved = false;

    public function __construct() {
        $this->legal_info = ContaiLegalPagesHelper::get_legal_info();
        $this->handle_form_submissions();
    }

    private function handle_form_submissions(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified below via check_admin_referer().
        if (isset($_POST['contai_save_legal_info'])) {
            check_admin_referer('contai_legal_info_nonce', 'contai_legal_info_nonce');
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have permission to perform this action.', '1platform-content-ai'));
            }
            $this->save_legal_info();
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified below via check_admin_referer().
        if (isset($_POST['contai_generate_legal_pages'])) {
            check_admin_referer('contai_legal_nonce', 'contai_legal_nonce');
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have permission to perform this action.', '1platform-content-ai'));
            }
            $this->api_generation_result = $this->generate_via_api();
        } elseif (isset($_POST['contai_save_cookie_settings'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            check_admin_referer('contai_cookie_nonce', 'contai_cookie_nonce');
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have permission to perform this action.', '1platform-content-ai'));
            }
            ContaiLegalPagesHelper::save_cookie_settings($_POST);
            $this->cookie_settings_saved = true;
        }
    }

    private function generate_via_api(): array {
        require_once __DIR__ . '/../../../services/legal/LegalPagesGenerator.php';

        $generator = new ContaiLegalPagesGenerator();
        return $generator->generate();
    }

    private function save_legal_info(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_form_submissions() via check_admin_referer().
        $owner = sanitize_text_field(wp_unslash($_POST['contai_legal_owner'] ?? ''));
        $address = sanitize_text_field(wp_unslash($_POST['contai_legal_address'] ?? ''));
        $activity = sanitize_text_field(wp_unslash($_POST['contai_legal_activity'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['contai_legal_email'] ?? ''));
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if (!empty($email) && !is_email($email)) {
            add_settings_error('contai_legal_info', 'invalid_email', __('Invalid email format', '1platform-content-ai'), 'error');
            return;
        }

        update_option('contai_legal_owner', $owner);
        update_option('contai_legal_address', $address);
        update_option('contai_legal_activity', $activity);
        update_option('contai_legal_email', $email);

        $this->legal_info_saved = true;
        $this->legal_info = ContaiLegalPagesHelper::get_legal_info();
    }

    public function render(): void {
        $this->render_notices();
        settings_errors('contai_legal_info');

        $this->render_legal_information_section();
        $this->render_generate_pages_section();
        $this->render_cookie_notice_section();
    }

    private function render_notices(): void {
        if ($this->legal_info_saved): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e('Legal information saved successfully!', '1platform-content-ai'); ?></p>
            </div>
        <?php endif;

        if ($this->cookie_settings_saved): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e('Cookie settings saved successfully!', '1platform-content-ai'); ?></p>
            </div>
        <?php endif;
    }

    private function render_legal_information_section(): void {
        $site_topic = esc_attr(get_option('contai_site_theme', ''));
        ?>
        <div class="contai-settings-panel contai-panel-legal-info">
            <div class="contai-panel-header">
                <div class="contai-panel-title-group">
                    <h2 class="contai-panel-title">
                        <span class="dashicons dashicons-businessman"></span>
                        <?php esc_html_e('Legal Information', '1platform-content-ai'); ?>
                    </h2>
                    <p class="contai-panel-description">
                        <?php esc_html_e('Configure the legal information used to generate your legal pages', '1platform-content-ai'); ?>
                    </p>
                </div>
            </div>

            <div class="contai-panel-body">
                <div class="contai-info-box contai-info-box-warning">
                    <div class="contai-info-box-icon">
                        <span class="dashicons dashicons-warning"></span>
                    </div>
                    <div class="contai-info-box-content">
                        <p><?php esc_html_e('All fields are required to generate legal pages via the API. Please fill in all information before generating.', '1platform-content-ai'); ?></p>
                    </div>
                </div>

                <form method="post" class="contai-legal-info-form">
                    <?php wp_nonce_field('contai_legal_info_nonce', 'contai_legal_info_nonce'); ?>

                    <div class="contai-form-grid contai-grid-2">
                        <div class="contai-form-group">
                            <label for="contai_legal_owner" class="contai-label">
                                <span class="dashicons dashicons-admin-users"></span>
                                <?php esc_html_e('Owner Name', '1platform-content-ai'); ?>
                                <span class="contai-required">*</span>
                            </label>
                            <input type="text"
                                id="contai_legal_owner"
                                name="contai_legal_owner"
                                value="<?php echo esc_attr($this->legal_info['owner']); ?>"
                                class="contai-input"
                                required
                                placeholder="<?php esc_attr_e('e.g., John Doe or Company Name', '1platform-content-ai'); ?>">
                            <p class="contai-help-text">
                                <span class="dashicons dashicons-info"></span>
                                <?php esc_html_e('Name of the person or organization responsible for the site', '1platform-content-ai'); ?>
                            </p>
                        </div>

                        <div class="contai-form-group">
                            <label for="contai_legal_email" class="contai-label">
                                <span class="dashicons dashicons-email"></span>
                                <?php esc_html_e('Contact Email', '1platform-content-ai'); ?>
                                <span class="contai-required">*</span>
                            </label>
                            <input type="email"
                                id="contai_legal_email"
                                name="contai_legal_email"
                                value="<?php echo esc_attr($this->legal_info['email']); ?>"
                                class="contai-input"
                                required
                                placeholder="<?php esc_attr_e('contact@example.com', '1platform-content-ai'); ?>">
                            <p class="contai-help-text">
                                <span class="dashicons dashicons-info"></span>
                                <?php esc_html_e('Contact email address for legal inquiries', '1platform-content-ai'); ?>
                            </p>
                        </div>

                        <div class="contai-form-group">
                            <label for="contai_legal_address" class="contai-label">
                                <span class="dashicons dashicons-location"></span>
                                <?php esc_html_e('Fiscal Address', '1platform-content-ai'); ?>
                                <span class="contai-required">*</span>
                            </label>
                            <input type="text"
                                id="contai_legal_address"
                                name="contai_legal_address"
                                value="<?php echo esc_attr($this->legal_info['address']); ?>"
                                class="contai-input"
                                required
                                placeholder="<?php esc_attr_e('Street, City, Country', '1platform-content-ai'); ?>">
                            <p class="contai-help-text">
                                <span class="dashicons dashicons-info"></span>
                                <?php esc_html_e('Registered address for legal purposes', '1platform-content-ai'); ?>
                            </p>
                        </div>

                        <div class="contai-form-group">
                            <label for="contai_legal_activity" class="contai-label">
                                <span class="dashicons dashicons-portfolio"></span>
                                <?php esc_html_e('Business Activity', '1platform-content-ai'); ?>
                                <span class="contai-required">*</span>
                            </label>
                            <input type="text"
                                id="contai_legal_activity"
                                name="contai_legal_activity"
                                value="<?php echo esc_attr($this->legal_info['activity']); ?>"
                                class="contai-input"
                                required
                                placeholder="<?php esc_attr_e('e.g., Digital content management', '1platform-content-ai'); ?>">
                            <p class="contai-help-text">
                                <span class="dashicons dashicons-info"></span>
                                <?php esc_html_e('Main business or social activity', '1platform-content-ai'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="contai-form-grid" style="margin-top: 4px;">
                        <div class="contai-form-group">
                            <label for="contai_site_topic_display" class="contai-label">
                                <span class="dashicons dashicons-admin-site"></span>
                                <?php esc_html_e('Site Topic', '1platform-content-ai'); ?>
                            </label>
                            <input type="text"
                                id="contai_site_topic_display"
                                value="<?php echo esc_attr( $site_topic ); ?>"
                                class="contai-input"
                                disabled
                                placeholder="<?php esc_attr_e('Not configured', '1platform-content-ai'); ?>">
                            <p class="contai-help-text">
                                <span class="dashicons dashicons-info"></span>
                                <?php esc_html_e('Set in Site Configuration. This value is sent to the API for context.', '1platform-content-ai'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="contai-button-group">
                        <button type="submit" name="contai_save_legal_info" class="button button-primary contai-button-action">
                            <span class="dashicons dashicons-yes"></span>
                            <span class="contai-button-text"><?php esc_html_e('Save Legal Information', '1platform-content-ai'); ?></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    private function render_generate_pages_section(): void {
        ?>
        <div class="contai-settings-panel contai-panel-generate-pages">
            <div class="contai-panel-header">
                <div class="contai-panel-title-group">
                    <h2 class="contai-panel-title">
                        <span class="dashicons dashicons-media-text"></span>
                        <?php esc_html_e('Generate Legal Pages', '1platform-content-ai'); ?>
                    </h2>
                    <p class="contai-panel-description">
                        <?php esc_html_e('Generate legal pages via the WPContentAI API that comply with GDPR and AdSense policies', '1platform-content-ai'); ?>
                    </p>
                </div>
            </div>

            <div class="contai-panel-body">
                <div class="contai-info-box contai-info-box-info">
                    <div class="contai-info-box-icon">
                        <span class="dashicons dashicons-info"></span>
                    </div>
                    <div class="contai-info-box-content">
                        <h4><?php esc_html_e('How it works', '1platform-content-ai'); ?></h4>
                        <ul>
                            <li><?php esc_html_e('The API generates legal pages based on your legal information and site topic.', '1platform-content-ai'); ?></li>
                            <li><?php esc_html_e('Pages that already exist (by slug) will not be replaced.', '1platform-content-ai'); ?></li>
                            <li><?php esc_html_e('To regenerate a page, delete it first and then run the generator again.', '1platform-content-ai'); ?></li>
                        </ul>
                    </div>
                </div>

                <form method="post" class="contai-legal-form">
                    <?php wp_nonce_field('contai_legal_nonce', 'contai_legal_nonce'); ?>
                    <div class="contai-button-group">
                        <button type="submit" name="contai_generate_legal_pages" class="button button-primary contai-button-action contai-button-generate">
                            <span class="dashicons dashicons-admin-page"></span>
                            <span class="contai-button-text"><?php esc_html_e('Generate Legal Pages', '1platform-content-ai'); ?></span>
                        </button>
                    </div>
                </form>

                <?php $this->render_generation_results(); ?>
            </div>
        </div>
        <?php
    }

    private function render_generation_results(): void {
        if ($this->api_generation_result === null) {
            return;
        }

        $r = $this->api_generation_result;
        ?>
        <div class="contai-generation-summary">
            <?php if (!empty($r['errors'])): ?>
                <div class="contai-result-card contai-result-error">
                    <div class="contai-result-card-header">
                        <span class="dashicons dashicons-dismiss"></span>
                        <strong><?php esc_html_e('Errors', '1platform-content-ai'); ?></strong>
                    </div>
                    <ul>
                        <?php foreach ($r['errors'] as $error): ?>
                            <li><?php echo esc_html($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($r['warnings'])): ?>
                <div class="contai-result-card contai-result-warning">
                    <div class="contai-result-card-header">
                        <span class="dashicons dashicons-warning"></span>
                        <strong><?php esc_html_e('Skipped Pages', '1platform-content-ai'); ?></strong>
                    </div>
                    <ul>
                        <?php foreach ($r['warnings'] as $warning): ?>
                            <li><?php echo esc_html($warning); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($r['created'] > 0): ?>
                <div class="contai-result-card contai-result-success">
                    <div class="contai-result-card-header">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <strong>
                            <?php
                            printf(
                                /* translators: %d: number of pages created */
                                esc_html(_n(
                                    '%d page created successfully',
                                    '%d pages created successfully',
                                    $r['created'],
                                    '1platform-content-ai'
                                )),
                                intval($r['created'])
                            ); ?>
                        </strong>
                    </div>
                    <?php if (!empty($r['messages'])): ?>
                        <ul>
                            <?php foreach ($r['messages'] as $msg): ?>
                                <li><?php echo esc_html($msg); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($r['created'] === 0 && empty($r['errors'])): ?>
                <div class="contai-result-card contai-result-warning">
                    <div class="contai-result-card-header">
                        <span class="dashicons dashicons-info"></span>
                        <strong><?php esc_html_e('No new pages were created', '1platform-content-ai'); ?></strong>
                    </div>
                    <p><?php esc_html_e('All pages already exist. Delete existing pages to regenerate them.', '1platform-content-ai'); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($r['skipped'] > 0 && $r['created'] > 0): ?>
                <p class="contai-generation-note">
                    <?php
                    printf(
                        /* translators: %d: number of pages skipped */
                        esc_html(_n(
                            '%d page was skipped because it already exists.',
                            '%d pages were skipped because they already exist.',
                            $r['skipped'],
                            '1platform-content-ai'
                        )),
                        intval($r['skipped'])
                    ); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_cookie_notice_section(): void {
        $enabled = get_option('contai_cookie_notice_enabled');
        if ($enabled === false) {
            $enabled = '1';
        }
        $current_text = get_option('contai_cookie_notice_text', ContaiLegalPagesHelper::get_cookie_text());
        ?>
        <div class="contai-settings-panel contai-panel-cookie-notice">
            <div class="contai-panel-header">
                <div class="contai-panel-title-group">
                    <h2 class="contai-panel-title">
                        <span class="dashicons dashicons-privacy"></span>
                        <?php esc_html_e('Cookie Notice Settings', '1platform-content-ai'); ?>
                    </h2>
                    <p class="contai-panel-description">
                        <?php esc_html_e('Manage cookie consent banner for your website', '1platform-content-ai'); ?>
                    </p>
                </div>
            </div>

            <div class="contai-panel-body">
                <form method="post" class="contai-cookie-form">
                    <?php wp_nonce_field('contai_cookie_nonce', 'contai_cookie_nonce'); ?>

                    <div class="contai-form-group">
                        <label class="contai-checkbox-label">
                            <input type="checkbox" name="contai_cookie_notice_enabled" value="1" <?php checked($enabled, '1'); ?>>
                            <span><?php esc_html_e('Enable cookie banner on website', '1platform-content-ai'); ?></span>
                        </label>
                        <p class="contai-help-text">
                            <span class="dashicons dashicons-info"></span>
                            <?php esc_html_e('Display cookie consent notice to your visitors', '1platform-content-ai'); ?>
                        </p>
                    </div>

                    <div class="contai-form-group">
                        <label for="contai_cookie_notice_text" class="contai-label">
                            <span class="dashicons dashicons-edit"></span>
                            <?php esc_html_e('Cookie Banner Text', '1platform-content-ai'); ?>
                        </label>
                        <textarea id="contai_cookie_notice_text" name="contai_cookie_notice_text" class="contai-textarea" rows="4"><?php echo esc_textarea($current_text); ?></textarea>
                        <p class="contai-help-text">
                            <span class="dashicons dashicons-info"></span>
                            <?php esc_html_e('Customize the text that appears in the cookie notice banner', '1platform-content-ai'); ?>
                        </p>
                    </div>

                    <div class="contai-button-group">
                        <button type="submit" name="contai_save_cookie_settings" class="button button-primary contai-button-action">
                            <span class="dashicons dashicons-yes"></span>
                            <span class="contai-button-text"><?php esc_html_e('Save Cookie Settings', '1platform-content-ai'); ?></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
}
