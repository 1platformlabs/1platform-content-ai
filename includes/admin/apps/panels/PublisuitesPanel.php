<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../../services/publisuites/PublisuitesService.php';
require_once __DIR__ . '/../../../helpers/license-helper.php';
require_once __DIR__ . '/publisuites/ConnectSection.php';
require_once __DIR__ . '/publisuites/VerificationSection.php';
require_once __DIR__ . '/publisuites/ConnectedSection.php';
require_once __DIR__ . '/publisuites/MarketplacePendingSection.php';
require_once __DIR__ . '/publisuites/OrdersSection.php';

/**
 * Publisuites Panel Controller.
 *
 * Resolves all UI state in a ViewModel array so section templates
 * render data without decision logic.
 */
class ContaiPublisuitesPanel
{
    private const NONCE_ACTION = 'contai_publisuites_action';
    private const NONCE_FIELD  = 'contai_publisuites_nonce';

    private ContaiPublisuitesService $service;

    public function __construct()
    {
        $this->service = new ContaiPublisuitesService();
    }

    public function render(): void
    {
        $this->enqueueScripts();

        if (!contai_has_active_license()) {
            $this->renderLicenseRequired();
            return;
        }

        $view_data = $this->buildViewData();
        $this->renderFlashMessage();
        $this->renderContent($view_data);
    }

    /**
     * Build a fully-resolved ViewModel for the template layer.
     *
     * Keys returned:
     *  - status_key        string  'not_connected' | 'pending_verification' | 'connected' | 'error' | 'website_required'
     *  - status_label       string  Human-readable badge text
     *  - status_class       string  CSS modifier class for the badge
     *  - primary_cta_label  string  Main action button label
     *  - primary_cta_action string  Form input name for the main CTA
     *  - secondary_cta_label  string|null  Optional secondary action label
     *  - secondary_cta_action string|null  Optional secondary form input name
     *  - site_url           string  Current site URL
     *  - config             array|null  Raw Publisuites config
     *  - verification_file_exists bool
     *  - nonce_action       string
     *  - nonce_field        string
     *  - benefits           array   Benefit card data
     *  - message            string  Contextual message from backend
     */
    private function buildViewData(): array
    {
        $status  = $this->service->initializeStatus();
        $config  = $status['config'];
        $site_url = $this->service->getSiteUrl();

        $base = [
            'site_url'                 => $site_url,
            'config'                   => $config,
            'nonce_action'             => self::NONCE_ACTION,
            'nonce_field'              => self::NONCE_FIELD,
            'verification_file_exists' => false,
            'benefits'                 => $this->getBenefits(),
            'message'                  => '',
            'secondary_cta_label'      => null,
            'secondary_cta_action'     => null,
        ];

        switch ($status['status']) {
            case 'website_required':
                return array_merge($base, [
                    'status_key'        => 'website_required',
                    'status_label'      => __('Setup Required', '1platform-content-ai'),
                    'status_class'      => 'contai-badge--warning',
                    'primary_cta_label' => null,
                    'primary_cta_action'=> null,
                    'message'           => $status['message'] ?? __('Website registration is required before connecting to the marketplace.', '1platform-content-ai'),
                ]);

            case 'not_connected':
                return array_merge($base, [
                    'status_key'        => 'not_connected',
                    'status_label'      => __('Not Connected', '1platform-content-ai'),
                    'status_class'      => 'contai-badge--neutral',
                    'primary_cta_label' => __('Connect to Marketplace', '1platform-content-ai'),
                    'primary_cta_action'=> 'contai_setup_publisuites',
                ]);

            case 'configured':
                if ($this->service->isVerified($config)) {
                    $marketplaceStatus = $config['marketplace_status'] ?? null;

                    if ($marketplaceStatus !== 'active') {
                        return array_merge($base, [
                            'status_key'           => 'marketplace_pending',
                            'status_label'         => __('Pending Approval', '1platform-content-ai'),
                            'status_class'         => 'contai-badge--warning',
                            'primary_cta_label'    => null,
                            'primary_cta_action'   => null,
                            'secondary_cta_label'  => __('Remove from Marketplace', '1platform-content-ai'),
                            'secondary_cta_action' => 'contai_delete_from_marketplace',
                        ]);
                    }

                    $view_data = array_merge($base, [
                        'status_key'           => 'connected',
                        'status_label'         => __('Connected', '1platform-content-ai'),
                        'status_class'         => 'contai-badge--success',
                        'primary_cta_label'    => null,
                        'primary_cta_action'   => null,
                        'secondary_cta_label'  => __('Disconnect', '1platform-content-ai'),
                        'secondary_cta_action' => 'contai_disconnect_publisuites',
                    ]);

                    $page = isset($_GET['ps_page']) ? absint($_GET['ps_page']) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    $ordersResponse = $this->service->getOrders($page);
                    if ($ordersResponse->isSuccess()) {
                        $ordersData = $ordersResponse->getData();
                        $view_data['orders'] = $ordersData['orders'] ?? [];
                        $view_data['total'] = $ordersData['total'] ?? 0;
                        $view_data['page'] = $ordersData['page'] ?? 1;
                        $view_data['page_size'] = $ordersData['page_size'] ?? 20;
                        $view_data['last_synced_at'] = $ordersData['last_synced_at'] ?? null;
                        $view_data['stale'] = $ordersData['stale'] ?? false;
                    } else {
                        $view_data['orders'] = [];
                        $view_data['total'] = 0;
                        $view_data['page'] = 1;
                        $view_data['page_size'] = 20;
                        $view_data['last_synced_at'] = null;
                        $view_data['stale'] = true;
                    }

                    return $view_data;
                }

                return array_merge($base, [
                    'status_key'                => 'pending_verification',
                    'status_label'              => __('Pending Verification', '1platform-content-ai'),
                    'status_class'              => 'contai-badge--warning',
                    'primary_cta_label'         => __('Verify Website', '1platform-content-ai'),
                    'primary_cta_action'        => 'contai_verify_publisuites',
                    'secondary_cta_label'       => __('Disconnect', '1platform-content-ai'),
                    'secondary_cta_action'      => 'contai_disconnect_publisuites',
                    'verification_file_exists'  => $this->service->verificationFileExists(),
                ]);

            default:
                return array_merge($base, [
                    'status_key'        => 'error',
                    'status_label'      => __('Error', '1platform-content-ai'),
                    'status_class'      => 'contai-badge--error',
                    'primary_cta_label' => null,
                    'primary_cta_action'=> null,
                    'message'           => __('An unexpected error occurred. Please try again.', '1platform-content-ai'),
                ]);
        }
    }

    private function getBenefits(): array
    {
        return [
            [
                'icon'        => 'dashicons-money-alt',
                'title'       => __('Monetize Content', '1platform-content-ai'),
                'description' => __('Earn revenue by publishing sponsored posts from premium brands.', '1platform-content-ai'),
            ],
            [
                'icon'        => 'dashicons-chart-line',
                'title'       => __('Track Performance', '1platform-content-ai'),
                'description' => __('Monitor your earnings and campaign performance in real-time.', '1platform-content-ai'),
            ],
            [
                'icon'        => 'dashicons-shield-alt',
                'title'       => __('Secure Integration', '1platform-content-ai'),
                'description' => __('Verified connection with industry-standard security protocols.', '1platform-content-ai'),
            ],
        ];
    }

    private function renderContent(array $view_data): void
    {
        switch ($view_data['status_key']) {
            case 'not_connected':
                $section = new ContaiPublisuitesConnectSection($view_data);
                $section->render();
                break;

            case 'pending_verification':
                $section = new ContaiPublisuitesVerificationSection($view_data);
                $section->render();
                break;

            case 'marketplace_pending':
                $section = new ContaiPublisuitesMarketplacePendingSection($view_data);
                $section->render();
                break;

            case 'connected':
                $ordersSection = new ContaiPublisuitesOrdersSection($view_data);
                $ordersSection->render();
                $section = new ContaiPublisuitesConnectedSection($view_data);
                $section->render();
                break;

            case 'website_required':
            case 'error':
            default:
                $this->renderAlert($view_data);
                break;
        }
    }

    private function renderAlert(array $view_data): void
    {
        $is_error = ($view_data['status_key'] === 'error');
        $box_class = $is_error ? 'contai-notice contai-notice-error' : 'contai-notice contai-notice-warning';
        $icon      = $is_error ? 'dashicons-warning' : 'dashicons-info-outline';
        $heading   = $is_error
            ? __('Something went wrong', '1platform-content-ai')
            : __('Website Registration Required', '1platform-content-ai');
        ?>
        <div class="contai-ps-alert <?php echo esc_attr($box_class); ?>" role="alert">
            <span class="dashicons <?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
            <div>
                <p><strong><?php echo esc_html($heading); ?></strong></p>
                <p><?php echo esc_html($view_data['message']); ?></p>
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
                        <?php esc_html_e('You need an active license to use the link building integration. Please activate your license to access this feature.', '1platform-content-ai'); ?>
                    </p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=contai-licenses')); ?>" class="button button-primary">
                        <?php esc_html_e('Activate License', '1platform-content-ai'); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderFlashMessage(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only flash messages.
        if (empty($_GET['contai_ps_message'])) {
            return;
        }

        $message        = sanitize_text_field(wp_unslash(urldecode($_GET['contai_ps_message'])));
        $type           = sanitize_key(wp_unslash($_GET['contai_ps_type'] ?? 'info'));
        $trace_id = isset($_GET['contai_ps_trace_id']) ? sanitize_text_field(wp_unslash(urldecode($_GET['contai_ps_trace_id']))) : null;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $valid_types = ['success', 'error', 'warning', 'info'];
        if (!in_array($type, $valid_types, true)) {
            $type = 'info';
        }

        $icon_map = [
            'success' => 'dashicons-yes-alt',
            'error'   => 'dashicons-warning',
            'warning' => 'dashicons-info-outline',
            'info'    => 'dashicons-info-outline',
        ];
        ?>
        <div class="contai-notice contai-notice-<?php echo esc_attr($type); ?> contai-ps-flash" role="status" aria-live="polite">
            <span class="dashicons <?php echo esc_attr($icon_map[$type]); ?>" aria-hidden="true"></span>
            <p>
                <?php echo esc_html($message); ?>
                <?php if (!empty($trace_id)): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=contai-logs&trace_id=' . urlencode($trace_id))); ?>">[Ref: <?php echo esc_html($trace_id); ?>]</a>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    private function enqueueScripts(): void
    {
        $baseDir = dirname(__DIR__);

        $jsFile = $baseDir . '/assets/js/publisuites.js';
        $jsUrl  = plugins_url('assets/js/publisuites.js', $baseDir . '/dummy.php');

        if (file_exists($jsFile)) {
            wp_enqueue_script(
                'contai-publisuites',
                $jsUrl,
                ['jquery'],
                filemtime($jsFile),
                true
            );
        }

    }
}
