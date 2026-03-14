<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../../providers/WebsiteProvider.php';
require_once __DIR__ . '/../../../services/search-console/SearchConsoleService.php';
require_once __DIR__ . '/../../../services/search-console/WebsiteStatusChecker.php';
require_once __DIR__ . '/../../../helpers/license-helper.php';
require_once __DIR__ . '/search-console/AddWebsiteSection.php';
require_once __DIR__ . '/search-console/VerificationSection.php';
require_once __DIR__ . '/search-console/VerifiedSection.php';

class ContaiSearchConsolePanel
{
    private ContaiWebsiteProvider $websiteProvider;
    private ContaiSearchConsoleService $service;
    private ContaiWebsiteStatusChecker $statusChecker;

    public function __construct()
    {
        $this->websiteProvider = new ContaiWebsiteProvider();
        $this->service         = new ContaiSearchConsoleService(null, $this->websiteProvider);
        $this->statusChecker   = new ContaiWebsiteStatusChecker();
    }

    public function render(): void
    {
        $this->enqueueAssets();

        if (!contai_has_active_license()) {
            $this->renderLicenseRequired();
            return;
        }

        $status = $this->websiteProvider->initializeWebsiteStatus();

        ?>
        <div class="contai-settings-panel contai-panel-search-console">
            <?php $this->renderMessage(); ?>
            <?php $this->renderContent($status); ?>
        </div>
        <?php
    }

    private function renderMessage(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only, no data modification.
        if (!isset($_GET['contai_sc_message']) || !isset($_GET['contai_sc_type'])) {
            return;
        }

        $message = urldecode(sanitize_text_field(wp_unslash($_GET['contai_sc_message'])));
        $type = sanitize_key(wp_unslash($_GET['contai_sc_type']));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $class = $type === 'success' ? 'notice-success' : 'notice-error';

        ?>
        <div class="notice <?php echo esc_attr($class); ?> is-dismissible">
            <p><?php echo esc_html($message); ?></p>
        </div>
        <?php
    }

    private function renderContent(array $status): void
    {
        $userProfile = $this->websiteProvider->getUserProfile();

        if (!$userProfile) {
            $this->renderUserNotConfigured();
            return;
        }

        if ($status['status'] === 'error') {
            $this->renderError($status['message'] ?? 'Unknown error');
            return;
        }

        if ($status['status'] === 'not_found') {
            $this->renderWebsiteNotFound();
            return;
        }

        $config = $status['config'];

        if ($this->statusChecker->isVerified($config)) {
            $verifiedSection = new ContaiSearchConsoleVerifiedSection($config);
            $verifiedSection->render();
            return;
        }

        $hasSearchConsoleData = !empty($config['file_name']);

        if (!$hasSearchConsoleData) {
            $addSection = new ContaiSearchConsoleAddWebsiteSection(
                $this->websiteProvider->getSiteUrl(),
                $this->websiteProvider->getSitemapUrls()
            );
            $addSection->render();
            return;
        }

        $verificationSection = new ContaiSearchConsoleVerificationSection(
            $config,
            $this->service->verificationFileExists()
        );
        $verificationSection->render();
    }

    private function renderWebsiteNotFound(): void
    {
        ?>
        <div class="contai-settings-section">
            <div class="contai-info-box contai-info-box-warning">
                <span class="dashicons dashicons-warning"></span>
                <div>
                    <p><strong><?php esc_html_e('Website Not Registered', '1platform-content-ai'); ?></strong></p>
                    <p>
                        <?php esc_html_e('Your website has not been registered with Content AI yet. Please go to API Keys & Licenses and reactivate your license to register the website.', '1platform-content-ai'); ?>
                    </p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=contai-licenses')); ?>" class="button button-primary">
                        <?php esc_html_e('Go to API Keys & Licenses', '1platform-content-ai'); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderUserNotConfigured(): void
    {
        ?>
        <div class="contai-settings-section">
            <div class="contai-info-box contai-info-box-error">
                <span class="dashicons dashicons-warning"></span>
                <div>
                    <p><strong><?php esc_html_e('User Profile Not Configured', '1platform-content-ai'); ?></strong></p>
                    <p>
                        <?php esc_html_e('Please configure your Content AI account in the API Keys & Licenses section first.', '1platform-content-ai'); ?>
                    </p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=contai-licenses')); ?>" class="button button-primary">
                        <?php esc_html_e('Go to API Keys & Licenses', '1platform-content-ai'); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderError(string $message): void
    {
        ?>
        <div class="contai-settings-section">
            <div class="contai-info-box contai-info-box-error">
                <span class="dashicons dashicons-warning"></span>
                <div>
                    <p><strong><?php esc_html_e('Error', '1platform-content-ai'); ?></strong></p>
                    <p><?php echo esc_html($message); ?></p>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderLicenseRequired(): void
    {
        ?>
        <div class="contai-settings-section">
            <div class="contai-info-box contai-info-box-warning">
                <span class="dashicons dashicons-lock"></span>
                <div>
                    <p><strong><?php esc_html_e('Active License Required', '1platform-content-ai'); ?></strong></p>
                    <p>
                        <?php esc_html_e('You need an active license to use the Search Console integration. Please activate your license to access this feature.', '1platform-content-ai'); ?>
                    </p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=contai-licenses')); ?>" class="button button-primary">
                        <?php esc_html_e('Activate License', '1platform-content-ai'); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    private function enqueueAssets(): void
    {
        $baseDir = dirname(__DIR__);
        $jsFile = $baseDir . '/assets/js/search-console.js';
        $jsUrl = plugins_url('assets/js/search-console.js', $baseDir . '/dummy.php');

        wp_enqueue_script(
            'contai-search-console',
            $jsUrl,
            ['jquery'],
            file_exists($jsFile) ? filemtime($jsFile) : '1.0.0',
            true
        );
    }
}
