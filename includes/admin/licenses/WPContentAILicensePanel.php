<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../services/user-profile/UserProfileService.php';
require_once __DIR__ . '/../../services/api/OnePlatformAuthService.php';
require_once __DIR__ . '/../../providers/WebsiteProvider.php';
require_once __DIR__ . '/../../services/setup/PublisuitesSetupService.php';
require_once __DIR__ . '/components/ActivateLicenseSection.php';
require_once __DIR__ . '/components/UserProfileSection.php';
require_once __DIR__ . '/components/CreateAccountSection.php';

class WPContentAILicensePanel
{
    private const NONCE_ACTION = 'contai_license_action';
    private const NONCE_FIELD = 'contai_license_nonce';

    private ContaiUserProfileService $service;
    private ?ContaiOnePlatformAuthService $authService;
    private ?string $message = null;
    private string $messageType = 'success';

    public function __construct(?ContaiUserProfileService $service = null, ?ContaiOnePlatformAuthService $authService = null)
    {
        $this->service = $service ?? new ContaiUserProfileService();
        $this->authService = $authService;
    }

    public function handleFormSubmissionEarly(): void
    {
        $this->handleFormSubmission();
    }

    public function render(): void
    {
        $this->enqueueStyles();

        // Check for success messages after redirect
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only read of GET params after redirect.
        if ( isset( $_GET['license_activated'] ) && sanitize_key( wp_unslash( $_GET['license_activated'] ) ) === '1' ) {
            $message = __('License activated successfully.', '1platform-content-ai');
            $this->messageType = 'success';

            if (isset($_GET['website_setup'])) {
                $websiteAction = sanitize_text_field(wp_unslash($_GET['website_setup']));

                switch ($websiteAction) {
                    case 'created':
                        $message .= ' ' . __('Website registered with Content AI.', '1platform-content-ai');
                        break;
                    case 'linked':
                        $message .= ' ' . __('Existing website linked.', '1platform-content-ai');
                        break;
                    case 'already_configured':
                        $message .= ' ' . __('Website already configured.', '1platform-content-ai');
                        break;
                }
            }

            if (isset($_GET['publisuites_setup'])) {
                $publiAction = sanitize_key(wp_unslash($_GET['publisuites_setup']));

                switch ($publiAction) {
                    case 'restored':
                        $message .= ' ' . __('Link building connection restored.', '1platform-content-ai');
                        break;
                    case 'verified':
                        $message .= ' ' . __('Link building verified and connected.', '1platform-content-ai');
                        break;
                    case 'full_activation':
                        $message .= ' ' . __('Link building activated.', '1platform-content-ai');
                        break;
                }
            }

            if ( isset( $_GET['website_setup_error'] ) && sanitize_key( wp_unslash( $_GET['website_setup_error'] ) ) === '1' ) {
                $message .= ' ' . __('Website setup could not be completed automatically. You can set it up later from Search Console.', '1platform-content-ai');
                $this->messageType = 'warning';
            }

            $this->message = $message;
        }

        if ( isset( $_GET['license_deactivated'] ) && sanitize_key( wp_unslash( $_GET['license_deactivated'] ) ) === '1' ) {
            $this->message = __('License deactivated successfully', '1platform-content-ai');
            $this->messageType = 'success';
        }

        if ( isset( $_GET['website_deleted'] ) && sanitize_key( wp_unslash( $_GET['website_deleted'] ) ) === '1' ) {
            $this->message = __('Website deleted successfully from Content AI servers', '1platform-content-ai');
            $this->messageType = 'success';
        }

        if ( isset( $_GET['website_delete_error'] ) && sanitize_key( wp_unslash( $_GET['website_delete_error'] ) ) === '1' ) {
            $this->message = __('Failed to delete website. Please try again.', '1platform-content-ai');
            $this->messageType = 'error';
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $status = $this->service->initializeUserProfile();

        if ($this->message) {
            $this->renderMessage();
        }

        if ($status['status'] === 'no_license') {
            $this->enqueueOnboardingAssets();
            $pending = $this->getValidOnboardingSession();
            $pending_session_id  = $pending ? $pending['session_id']  : null;
            $pending_payment_url = $pending ? $pending['payment_url'] : null;
            ContaiCreateAccountSection::render($pending_session_id, $pending_payment_url);
            $section = new ContaiActivateLicenseSection(self::NONCE_ACTION, self::NONCE_FIELD);
            $section->render();
            return;
        }

        if ($status['status'] === 'error') {
            $this->renderError($status['message'] ?? 'Unknown error');
            return;
        }

        if ($status['status'] === 'active' && $status['profile']) {
            $connected = $this->validateConnectionStatus();
            $websiteProvider = new ContaiWebsiteProvider();
            $websiteConfig = $websiteProvider->getWebsiteConfig();
            $section = new ContaiUserProfileSection(
                $status['profile'],
                self::NONCE_ACTION,
                self::NONCE_FIELD,
                $websiteConfig,
                $connected
            );
            $section->render();
            return;
        }

        $this->enqueueOnboardingAssets();
        $pending_session = $this->getValidOnboardingSession();
        ContaiCreateAccountSection::render($pending_session);
        $section = new ContaiActivateLicenseSection(self::NONCE_ACTION, self::NONCE_FIELD);
        $section->render();
    }

    private function handleFormSubmission(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified below via wp_verify_nonce().
        if (!isset($_POST[self::NONCE_FIELD])) {
            return;
        }

        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD])), self::NONCE_ACTION)) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above via wp_verify_nonce().
        if (isset($_POST['contai_activate_license'])) {
            $this->handleActivateLicense();
        }

        if (isset($_POST['contai_deactivate_license'])) {
            $this->handleDeactivateLicense();
        }

        if (isset($_POST['contai_refresh_profile'])) {
            $this->handleRefreshProfile();
        }

        if (isset($_POST['contai_refresh_tokens'])) {
            $this->handleRefreshTokens();
        }

        if (isset($_POST['contai_delete_website'])) {
            $this->handleDeleteWebsite();
            $this->handleDeactivateLicense();
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    private function handleActivateLicense(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handleFormSubmission() via wp_verify_nonce().
        $apiKey = isset($_POST['contai_api_key']) ? sanitize_text_field(wp_unslash($_POST['contai_api_key'])) : '';

        if (empty($apiKey)) {
            $this->message = __('API key is required', '1platform-content-ai');
            $this->messageType = 'error';
            return;
        }

        // Step 1: Save API key and clear stale user token from any previous key
        $this->service->saveApiKey($apiKey);
        $authService = ContaiOnePlatformAuthService::create();
        $authService->clearUserToken();

        // Clear onboarding session — activation complete, no more polling needed
        delete_transient('contai_onboarding_session_' . get_current_user_id());

        // Step 2: Authenticate with new API key
        $result = $this->service->refreshUserProfile();

        if (!$result['success']) {
            $this->service->deleteApiKey();
            $this->message = $result['message'] ?? __('Failed to validate API key', '1platform-content-ai');
            $this->messageType = 'error';
            return;
        }

        // Step 3: Ensure website exists (search or create)
        $websiteProvider = new ContaiWebsiteProvider();
        $websiteResult = $websiteProvider->ensureWebsiteExists();

        $redirect_url = admin_url('admin.php?page=contai-licenses');
        $args = ['license_activated' => '1'];

        if ($websiteResult['success']) {
            $args['website_setup'] = sanitize_key($websiteResult['action']);

            // Step 4: Auto-connect Publisuites (graceful — never blocks activation)
            $websiteData = $websiteResult['website_data'] ?? [];
            if (!empty($websiteData)) {
                try {
                    $publiSetup = new ContaiPublisuitesSetupService();
                    $publiResult = $publiSetup->autoConnect($websiteData);
                    $args['publisuites_setup'] = sanitize_key($publiResult['action'] ?? 'skipped');
                } catch (\Exception $e) {
                    $args['publisuites_setup'] = 'error';
                }
            }
        } else {
            $args['website_setup_error'] = '1';
        }

        wp_safe_redirect(add_query_arg($args, $redirect_url));
        exit;
    }

    private function handleDeactivateLicense(): void
    {
        $this->service->deleteApiKey();

        // Clear authentication token
        $authService = ContaiOnePlatformAuthService::create();
        $authService->clearToken();

        // Clear onboarding session to prevent re-activation polling loop
        delete_transient('contai_onboarding_session_' . get_current_user_id());

        // Redirect to refresh the page and hide API Keys section
        $redirect_url = admin_url('admin.php?page=contai-licenses');
        wp_safe_redirect(add_query_arg(['license_deactivated' => '1'], $redirect_url));
        exit;
    }

    private function handleDeleteWebsite(): void
    {
        $websiteProvider = new ContaiWebsiteProvider();
        $websiteConfig = $websiteProvider->getWebsiteConfig();
        $redirect_url = admin_url('admin.php?page=contai-licenses');

        if (!$websiteConfig || empty($websiteConfig['websiteId'])) {
            wp_safe_redirect(add_query_arg(['website_delete_error' => '1'], $redirect_url));
            exit;
        }

        $response = $websiteProvider->deleteWebsite();

        if (!$response->isSuccess()) {
            wp_safe_redirect(add_query_arg(['website_delete_error' => '1'], $redirect_url));
            exit;
        }

        $websiteProvider->deleteWebsiteConfig();
    }

    private function handleRefreshProfile(): void
    {
        $result = $this->service->refreshUserProfile();

        if (!$result['success']) {
            $this->message = $result['message'] ?? __('Failed to refresh profile', '1platform-content-ai');
            $this->messageType = 'error';
            return;
        }

        $this->message = __('Profile refreshed successfully', '1platform-content-ai');
        $this->messageType = 'success';
    }

    /**
     * Force-refresh both app and user tokens, then validate with a profile fetch.
     *
     * cURL equivalent flow (import to Postman):
     * # Step 1: Get fresh app token
     * curl -X POST https://api.1platform.pro/api/v1/auth/token \
     *   -H "Content-Type: application/json" \
     *   -d '{"apiKey": "<APP_API_KEY>"}'
     *
     * # Step 2: Get fresh user token
     * curl -X POST https://api.1platform.pro/api/v1/users/token \
     *   -H "Content-Type: application/json" \
     *   -H "Authorization: Bearer <FRESH_APP_TOKEN>" \
     *   -d '{"apiKey": "<USER_API_KEY>"}'
     *
     * # Step 3: Validate with profile request
     * curl -X GET https://api.1platform.pro/api/v1/users/profile \
     *   -H "Content-Type: application/json" \
     *   -H "Authorization: Bearer <FRESH_APP_TOKEN>" \
     *   -H "x-user-token: <FRESH_USER_TOKEN>"
     */
    private function handleRefreshTokens(): void
    {
        $result = $this->getAuthService()->forceRefreshAllTokens();

        if (!$result['success']) {
            $this->message = $result['message'] ?? __('Failed to refresh tokens', '1platform-content-ai');
            $this->messageType = 'error';
            return;
        }

        $profileResult = $this->service->refreshUserProfile();

        if (!$profileResult['success']) {
            $this->message = __('Tokens refreshed, but profile validation failed: ', '1platform-content-ai') . ($profileResult['message'] ?? '');
            $this->messageType = 'warning';
            return;
        }

        $this->message = __('Tokens refreshed and connection verified successfully', '1platform-content-ai');
        $this->messageType = 'success';
    }

    /**
     * Validate connection by making a real API call (GET /users/profile).
     *
     * The ContaiOnePlatformClient auto-refreshes expired tokens on 401,
     * so this call also triggers token renewal if needed.
     */
    private function validateConnectionStatus(): bool
    {
        $response = $this->service->fetchUserProfile();

        if ($response->isSuccess()) {
            $data = $response->getData();
            if ($data) {
                $this->service->saveUserProfile($data);
            }
            $this->clearStaleTokenErrors();
            return true;
        }

        // First attempt failed — force-refresh all tokens and retry once.
        // This covers cases where token generation failed transiently
        // and the OnePlatformClient retry was also exhausted.
        contai_log('Content AI: Profile fetch failed on first attempt, force-refreshing tokens and retrying');
        $refreshResult = $this->getAuthService()->forceRefreshAllTokens();

        if (!$refreshResult['success']) {
            return false;
        }

        $retryResponse = $this->service->fetchUserProfile();

        if ($retryResponse->isSuccess()) {
            $data = $retryResponse->getData();
            if ($data) {
                $this->service->saveUserProfile($data);
            }
            $this->clearStaleTokenErrors();
            return true;
        }

        return false;
    }

    private function clearStaleTokenErrors(): void
    {
        $this->getAuthService()->clearErrors();
    }

    private function getAuthService(): ContaiOnePlatformAuthService
    {
        return $this->authService ?? ContaiOnePlatformAuthService::create();
    }

    private function renderMessage(): void
    {
        $classMap = [
            'success' => 'notice-success',
            'error'   => 'notice-error',
            'warning' => 'notice-warning',
        ];
        $class = $classMap[$this->messageType] ?? 'notice-info';
        ?>
        <div class="notice <?php echo esc_attr($class); ?> is-dismissible">
            <p><?php echo esc_html($this->message); ?></p>
        </div>
        <?php
    }

    private function renderError(string $message): void
    {
        ?>
        <div class="contai-settings-panel contai-license-panel">
            <div class="contai-panel-header">
                <div class="contai-panel-title-group">
                    <h2 class="contai-panel-title">
                        <span class="dashicons dashicons-superhero-alt"></span>
                        <?php esc_html_e('Content AI License', '1platform-content-ai'); ?>
                    </h2>
                </div>
            </div>

            <div class="contai-panel-body">
                <div class="contai-info-box contai-info-box-error">
                    <div class="contai-info-box-icon">
                        <span class="dashicons dashicons-warning"></span>
                    </div>
                    <div class="contai-info-box-content">
                        <p><strong><?php esc_html_e('Connection Error', '1platform-content-ai'); ?></strong></p>
                        <p><?php echo esc_html($message); ?></p>
                    </div>
                </div>

                <form method="post" class="contai-license-form">
                    <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                    <div class="contai-form-actions">
                        <button type="submit" name="contai_refresh_profile" class="button button-primary">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e('Retry Connection', '1platform-content-ai'); ?>
                        </button>
                        <button type="submit" name="contai_deactivate_license" class="button button-secondary"
                                onclick="return confirm('<?php echo esc_js(__('Are you sure you want to remove the license?', '1platform-content-ai')); ?>');">
                            <span class="dashicons dashicons-dismiss"></span>
                            <?php esc_html_e('Remove License', '1platform-content-ai'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    private function getValidOnboardingSession(): ?array
    {
        $transient_key = 'contai_onboarding_session_' . get_current_user_id();
        $session = get_transient($transient_key);

        if (!$session) {
            return null;
        }

        // Support legacy string format (session_id only)
        if (is_string($session)) {
            if (!preg_match('/^[a-f0-9\-]{36}$/', $session)) {
                return null;
            }
            return array('session_id' => $session, 'payment_url' => '');
        }

        if (!is_array($session) || empty($session['session_id'])) {
            return null;
        }

        if (!preg_match('/^[a-f0-9\-]{36}$/', $session['session_id'])) {
            return null;
        }

        return $session;
    }

    private function enqueueStyles(): void
    {
        $cssFile = __DIR__ . '/assets/css/license.css';
        $cssUrl = plugins_url('assets/css/license.css', __FILE__);

        wp_enqueue_style(
            'contai-license',
            $cssUrl,
            array('contai-admin-licenses'),
            file_exists($cssFile) ? filemtime($cssFile) : '1.0.0'
        );
    }

    private function enqueueOnboardingAssets(): void
    {
        $jsFile = __DIR__ . '/assets/js/contai-onboarding.js';
        $jsUrl  = plugins_url('assets/js/contai-onboarding.js', __FILE__);

        wp_enqueue_script(
            'contai-onboarding',
            $jsUrl,
            array(),
            file_exists($jsFile) ? filemtime($jsFile) : '1.0.0',
            true
        );

        wp_localize_script('contai-onboarding', 'contaiOnboarding', array(
            'restUrl' => esc_url_raw(rest_url('contai/v1/onboarding/')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'i18n'    => array(
                'processing'    => esc_html__('Processing your payment...', '1platform-content-ai'),
                'timeout'       => esc_html__('Payment is being processed. You can close this tab and return later.', '1platform-content-ai'),
                'failed'        => esc_html__('Payment was not completed. Please try again.', '1platform-content-ai'),
                'success'       => esc_html__('Account created! Activating your license...', '1platform-content-ai'),
                'emailRequired' => esc_html__('Please enter your email address.', '1platform-content-ai'),
                'minAmount'     => esc_html__('Minimum amount is $5.00 USD.', '1platform-content-ai'),
                'existingKey'   => esc_html__('Already have an API key? Click here', '1platform-content-ai'),
                'createNew'     => esc_html__('Create a new account instead', '1platform-content-ai'),
                'alreadyClaimed' => esc_html__('API key was already retrieved. Please enter it below.', '1platform-content-ai'),
                'invalidKey'    => esc_html__('Invalid API key received. Please enter it manually.', '1platform-content-ai'),
                'openPayment'   => esc_html__('Click here to complete payment', '1platform-content-ai'),
            ),
        ));
    }
}
