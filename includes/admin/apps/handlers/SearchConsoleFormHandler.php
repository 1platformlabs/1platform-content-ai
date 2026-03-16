<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../../providers/WebsiteProvider.php';
require_once __DIR__ . '/../../../services/search-console/SearchConsoleService.php';

class ContaiSearchConsoleFormHandler
{
    private const NONCE_ACTION = 'contai_search_console_action';
    private const NONCE_FIELD = 'contai_search_console_nonce';

    private ContaiWebsiteProvider $websiteProvider;
    private ContaiSearchConsoleService $service;

    public function __construct(
        ?ContaiWebsiteProvider $websiteProvider = null,
        ?ContaiSearchConsoleService $service = null
    ) {
        $this->websiteProvider = $websiteProvider ?? new ContaiWebsiteProvider();
        $this->service         = $service ?? new ContaiSearchConsoleService(null, $this->websiteProvider);
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

        if (isset($_POST['contai_add_website'])) {
            $this->handleAddWebsite();
        }

        if (isset($_POST['contai_verify_website'])) {
            $this->handleVerifyWebsite();
        }

        if (isset($_POST['contai_disconnect_website'])) {
            $this->handleDisconnectWebsite();
        }

        if (isset($_POST['contai_delete_website'])) {
            $this->handleDeleteWebsite();
        }

        if (isset($_POST['contai_create_verification_file'])) {
            $this->handleCreateVerificationFile();
        }
    }

    private function handleAddWebsite(): void
    {
        $response = $this->service->addToSearchConsole();

        if (!$response->isSuccess()) {
            $this->redirectWithMessage('error', $response->getMessage(), $response->getTraceId());
            return;
        }

        $this->websiteProvider->saveSearchConsoleConfig($response->getData());

        $sitemaps = $this->websiteProvider->getSitemapUrls();

        if (!empty($sitemaps)) {
            $sitemapResponse = $this->service->submitSitemaps($sitemaps);

            if ($sitemapResponse->isSuccess()) {
                $this->websiteProvider->saveSitemapsConfig($sitemapResponse->getData());
            }
        }

        $this->redirectWithMessage('success', __('Website added to Search Console successfully', '1platform-content-ai'));
    }

    private function handleVerifyWebsite(): void
    {
        $response = $this->service->verifyWebsite();

        if (!$response->isSuccess()) {
            $this->redirectWithMessage('error', $response->getMessage(), $response->getTraceId());
            return;
        }

        $this->websiteProvider->saveSearchConsoleConfig($response->getData());

        $this->redirectWithMessage('success', __('Website verified successfully', '1platform-content-ai'));
    }

    private function handleDisconnectWebsite(): void
    {
        $response = $this->websiteProvider->deleteWebsite();

        if (!$response->isSuccess() && $response->getStatusCode() !== 404) {
            $this->redirectWithMessage('error', $response->getMessage(), $response->getTraceId());
            return;
        }

        $this->websiteProvider->deleteWebsiteConfig();

        $this->redirectWithMessage('success', __('Website disconnected successfully', '1platform-content-ai'));
    }

    private function handleDeleteWebsite(): void
    {
        $response = $this->websiteProvider->deleteWebsite();

        if (!$response->isSuccess()) {
            $this->redirectWithMessage('error', $response->getMessage(), $response->getTraceId());
            return;
        }

        $this->websiteProvider->deleteWebsiteConfig();

        $this->redirectWithMessage('success', __('Website deleted successfully', '1platform-content-ai'));
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
        $redirectUrl = admin_url('admin.php?page=contai-apps&section=search-console');
        $args = [
            'contai_sc_message' => urlencode($message),
            'contai_sc_type' => $type,
        ];
        if (!empty($trace_id)) {
            $args['contai_sc_trace_id'] = urlencode($trace_id);
        }

        wp_safe_redirect(add_query_arg($args, $redirectUrl));
        exit;
    }
}
