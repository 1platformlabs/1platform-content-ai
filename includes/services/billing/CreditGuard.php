<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/BillingService.php';

class ContaiCreditGuard {

    private const CACHE_KEY = 'contai_billing_cache';
    private const CACHE_TTL = 300; // 5 minutes

    public const INSUFFICIENT_CREDITS_PREFIX = 'INSUFFICIENT_CREDITS: ';

    private ?ContaiBillingService $billingService;

    public function __construct(?ContaiBillingService $billingService = null) {
        $this->billingService = $billingService;
    }

    private function getBillingService(): ContaiBillingService {
        if ($this->billingService === null) {
            $this->billingService = new ContaiBillingService();
        }
        return $this->billingService;
    }

    /**
     * Validate that the user has credits available.
     *
     * @return array{has_credits: bool, balance: float, currency: string, message: string}
     */
    public function validateCredits(): array {
        $billing = $this->getCachedBilling();

        if ($billing === null) {
            return [
                'has_credits' => false,
                'balance'     => 0,
                'currency'    => 'USD',
                'message'     => __('Unable to verify your balance. Please try again.', '1platform-content-ai'),
            ];
        }

        $balance  = (float) ($billing['balance'] ?? 0);
        $currency = $billing['currency'] ?? 'USD';

        if ($balance <= 0) {
            return [
                'has_credits' => false,
                'balance'     => $balance,
                'currency'    => $currency,
                'message'     => __('Insufficient balance. Please add credits before generating content.', '1platform-content-ai'),
            ];
        }

        return [
            'has_credits' => true,
            'balance'     => $balance,
            'currency'    => $currency,
            'message'     => '',
        ];
    }

    /**
     * Get cached billing data. Falls back to API if cache is empty.
     *
     * @return array|null Billing data array or null on failure.
     */
    public function getCachedBilling(): ?array {
        $cached = get_transient(self::CACHE_KEY);

        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        return $this->fetchAndCacheBilling();
    }

    /**
     * Force-refresh the billing cache (e.g. after a purchase).
     */
    public function invalidateCache(): void {
        delete_transient(self::CACHE_KEY);
    }

    /**
     * Check if an error message indicates insufficient credits.
     *
     * @param string $errorMessage
     * @return bool
     */
    public static function isInsufficientCreditsError(string $errorMessage): bool {
        return strpos($errorMessage, self::INSUFFICIENT_CREDITS_PREFIX) === 0;
    }

    /**
     * Fetch billing from API and store in transient cache.
     *
     * @return array|null
     */
    private function fetchAndCacheBilling(): ?array {
        $response = $this->getBillingService()->getBilling();

        if (!$response->isSuccess()) {
            return null;
        }

        $data = $response->getData();
        $billing = $data['billing'] ?? $data;

        set_transient(self::CACHE_KEY, $billing, self::CACHE_TTL);

        return $billing;
    }
}
