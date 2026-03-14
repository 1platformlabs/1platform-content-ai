<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../../services/billing/BillingService.php';

class ContaiTopUpHandler
{
    public const NONCE_ACTION = 'contai_billing_topup_action';
    public const NONCE_FIELD = 'contai_billing_topup_nonce';

    private const MIN_AMOUNT = 5;
    private const MAX_AMOUNT = 200;

    private ContaiBillingService $service;

    public function __construct(?ContaiBillingService $service = null)
    {
        $this->service = $service ?? new ContaiBillingService();
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

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via wp_verify_nonce().
        if (isset($_POST['contai_billing_topup'])) {
            $this->handleTopUp();
        }
    }

    private function handleTopUp(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handleRequest() via wp_verify_nonce().
        $amount = isset($_POST['contai_topup_amount']) ? floatval(wp_unslash($_POST['contai_topup_amount'])) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handleRequest() via wp_verify_nonce().
        $currency = isset($_POST['contai_topup_currency']) ? sanitize_text_field(wp_unslash($_POST['contai_topup_currency'])) : '';

        if ($amount < self::MIN_AMOUNT || $amount > self::MAX_AMOUNT) {
            $this->redirectWithMessage(
                'error',
                sprintf(
                    /* translators: %1$d: minimum allowed amount, %2$d: maximum allowed amount */
                    __('Amount must be between %1$d and %2$d.', '1platform-content-ai'),
                    self::MIN_AMOUNT,
                    self::MAX_AMOUNT
                )
            );
            return;
        }

        if (empty($currency)) {
            $this->redirectWithMessage('error', __('Currency is required.', '1platform-content-ai'));
            return;
        }

        $response = $this->service->createTransaction($amount, $currency, 'Account top-up');

        if (!$response->isSuccess()) {
            $this->redirectWithMessage('error', $response->getMessage());
            return;
        }

        $data = $response->getData();
        $payment_url = $data['payment_url'] ?? null;

        if (!empty($payment_url) && filter_var($payment_url, FILTER_VALIDATE_URL)) {
            // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Redirect to external payment gateway.
            wp_redirect(esc_url_raw($payment_url));
            exit;
        }

        $this->redirectWithMessage('error', __('Payment URL not available. Please try again.', '1platform-content-ai'));
    }

    private function redirectWithMessage(string $type, string $message): void
    {
        $redirect_url = admin_url('admin.php?page=contai-billing&section=overview');
        $args = [
            'contai_bl_message' => urlencode($message),
            'contai_bl_type' => $type,
        ];

        wp_safe_redirect(add_query_arg($args, $redirect_url));
        exit;
    }

}
