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
