<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../../services/publisuites/PublisuitesService.php';
require_once __DIR__ . '/../../../services/setup/PublisuitesSetupService.php';

class ContaiPublisuitesFormHandler
{
    private const NONCE_ACTION = 'contai_publisuites_action';
    private const NONCE_FIELD = 'contai_publisuites_nonce';

    private ContaiPublisuitesService $service;

    public function __construct(?ContaiPublisuitesService $service = null)
    {
        $this->service = $service ?? new ContaiPublisuitesService();
    }

    public function handleRequest(): void
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

        if (isset($_POST['contai_setup_publisuites'])) {
            $this->handleSetup();
        }

        if (isset($_POST['contai_connect_publisuites'])) {
            $this->handleConnect();
        }

        if (isset($_POST['contai_verify_publisuites'])) {
            $this->handleVerify();
        }

        if (isset($_POST['contai_disconnect_publisuites'])) {
            $this->handleDisconnect();
        }

        if (isset($_POST['contai_create_verification_file'])) {
            $this->handleCreateVerificationFile();
            return;
        }

        if (isset($_POST['contai_sync_publisuites'])) {
            $this->handleSync();
            return;
        }

        if (isset($_POST['contai_accept_order'])) {
            $this->handleAcceptOrder();
            return;
        }

        if (isset($_POST['contai_reject_order'])) {
            $this->handleRejectOrder();
            return;
        }

        if (isset($_POST['contai_reopen_order'])) {
            $this->handleReopenOrder();
            return;
        }

        if (isset($_POST['contai_send_url'])) {
            $this->handleSendUrl();
            return;
        }
    }

    private function handleSetup(): void
    {
        $setupService = new ContaiPublisuitesSetupService($this->service);
        $result = $setupService->activatePublisuites();

        if (!$result['success']) {
            $errorMsg = implode('. ', $result['errors']);
            $this->redirectWithMessage('error', $errorMsg);
            return;
        }

        $this->redirectWithMessage(
            'success',
            __('Website connected to marketplace successfully', '1platform-content-ai')
        );
    }

    private function handleConnect(): void
    {
        $response = $this->service->connectWebsite();

        if (!$response->isSuccess()) {
            $this->redirectWithMessage('error', $response->getMessage(), $response->getTraceId());
            return;
        }

        $data = $response->getData();

        // Save configuration
        $configData = [
            'publisuites_id' => $data['publisuites_id'] ?? '',
            'verification_file_name' => $data['verification_file_name'] ?? '',
            'verification_file_content' => $data['verification_file_content'] ?? '',
            'message' => $data['message'] ?? '',
            'status' => 'pending_verification',
            'verified' => false,
        ];

        $this->service->savePublisuitesConfig($configData);

        $this->redirectWithMessage('success', __('Connected to marketplace successfully. Please verify your website.', '1platform-content-ai'));
    }

    private function handleVerify(): void
    {
        $response = $this->service->verifyWebsite();

        if (!$response->isSuccess()) {
            $this->redirectWithMessage('error', $response->getMessage(), $response->getTraceId());
            return;
        }

        $data = $response->getData();

        // Update configuration with verification status
        $config = $this->service->getPublisuitesConfig();
        if ($config) {
            $config['verified'] = $data['verified'] ?? false;
            $config['verifiedAt'] = $data['verified_at'] ?? null;
            $config['status'] = ($data['verified'] ?? false) ? 'active' : 'pending_verification';

            $this->service->savePublisuitesConfig($config);
        }

        $this->redirectWithMessage('success', __('Website verified successfully', '1platform-content-ai'));
    }

    private function handleDisconnect(): void
    {
        // Only delete local configuration, don't call API
        $this->service->deletePublisuitesConfig();

        $this->redirectWithMessage('success', __('Disconnected from marketplace successfully', '1platform-content-ai'));
    }

    private function handleCreateVerificationFile(): void
    {
        $result = $this->service->createVerificationFile();

        if (!$result['success']) {
            $this->redirectWithMessage('error', $result['message']);
            return;
        }

        $this->redirectWithMessage('success', $result['message']);
    }

    private function handleSync(): void
    {
        $response = $this->service->triggerSync();

        if (!$response->isSuccess()) {
            $this->redirectWithMessage('error', $response->getMessage(), $response->getTraceId());
            return;
        }

        $this->redirectWithMessage('success', __('Orders synced successfully', '1platform-content-ai'));
    }

    private function handleAcceptOrder(): void
    {
        $orderId = isset($_POST['contai_order_id']) ? absint($_POST['contai_order_id']) : 0;

        if ($orderId <= 0) {
            $this->redirectWithMessage('error', __('Invalid order ID', '1platform-content-ai'));
            return;
        }

        $response = $this->service->acceptOrder($orderId);

        if (!$response->isSuccess()) {
            $this->redirectWithMessage('error', $response->getMessage(), $response->getTraceId());
            return;
        }

        $this->redirectWithMessage('success', __('Order accepted', '1platform-content-ai'));
    }

    private function handleRejectOrder(): void
    {
        $orderId = isset($_POST['contai_order_id']) ? absint($_POST['contai_order_id']) : 0;

        if ($orderId <= 0) {
            $this->redirectWithMessage('error', __('Invalid order ID', '1platform-content-ai'));
            return;
        }

        $response = $this->service->rejectOrder($orderId);

        if (!$response->isSuccess()) {
            $this->redirectWithMessage('error', $response->getMessage(), $response->getTraceId());
            return;
        }

        $this->redirectWithMessage('success', __('Order rejected', '1platform-content-ai'));
    }

    private function handleReopenOrder(): void
    {
        $orderId = isset($_POST['contai_order_id']) ? absint($_POST['contai_order_id']) : 0;

        if ($orderId <= 0) {
            $this->redirectWithMessage('error', __('Invalid order ID', '1platform-content-ai'));
            return;
        }

        $response = $this->service->reopenOrder($orderId);

        if (!$response->isSuccess()) {
            $this->redirectWithMessage('error', $response->getMessage(), $response->getTraceId());
            return;
        }

        $this->redirectWithMessage('success', __('Reopen request sent', '1platform-content-ai'));
    }

    private function handleSendUrl(): void
    {
        $orderId = isset($_POST['contai_order_id']) ? absint($_POST['contai_order_id']) : 0;

        if ($orderId <= 0) {
            $this->redirectWithMessage('error', __('Invalid order ID', '1platform-content-ai'));
            return;
        }

        $url = isset($_POST['contai_delivery_url']) ? esc_url_raw(wp_unslash($_POST['contai_delivery_url'])) : '';

        if (empty($url) || strpos($url, 'http') !== 0) {
            $this->redirectWithMessage('error', __('Please provide a valid URL starting with http', '1platform-content-ai'));
            return;
        }

        $response = $this->service->sendUrl($orderId, $url);

        if (!$response->isSuccess()) {
            $this->redirectWithMessage('error', $response->getMessage(), $response->getTraceId());
            return;
        }

        $this->redirectWithMessage('success', __('URL submitted successfully', '1platform-content-ai'));
    }

    private function redirectWithMessage(string $type, string $message, ?string $trace_id = null): void
    {
        $redirectUrl = admin_url('admin.php?page=contai-apps&section=publisuites');
        $args = [
            'contai_ps_message' => urlencode($message),
            'contai_ps_type' => $type,
        ];
        if (!empty($trace_id)) {
            $args['contai_ps_trace_id'] = urlencode($trace_id);
        }

        wp_safe_redirect(add_query_arg($args, $redirectUrl));
        exit;
    }

    public function getNonceField(): string
    {
        return self::NONCE_FIELD;
    }

    public function getNonceAction(): string
    {
        return self::NONCE_ACTION;
    }
}
