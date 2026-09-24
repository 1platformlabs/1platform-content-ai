<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../api/OnePlatformClient.php';
require_once __DIR__ . '/../api/OnePlatformEndpoints.php';
require_once __DIR__ . '/../config/Config.php';
require_once __DIR__ . '/../../providers/UserProvider.php';

class ContaiBillingService
{
    private ContaiOnePlatformClient $client;
    private ContaiUserProvider $userProvider;

    public function __construct(?ContaiOnePlatformClient $client = null, ?ContaiUserProvider $userProvider = null)
    {
        $this->client = $client ?? ContaiOnePlatformClient::create(ContaiConfig::getInstance());
        $this->userProvider = $userProvider ?? new ContaiUserProvider();
    }

    public function getUserProfile(): ?array
    {
        return $this->userProvider->getUserProfile();
    }

    public function getBilling(): ContaiOnePlatformResponse
    {
        return $this->client->get(ContaiOnePlatformEndpoints::USERS_BILLING);
    }

    /**
     * Buys credit for the site owner's OWN balance.
     *
     * Goes to the top-up endpoint, not to the transactions one: that one collects
     * from a buyer, so it neither records the charge as a top-up nor keeps the
     * owner's saved card between top-ups.
     */
    public function createTransaction(float $amount, string $currency, string $description): ContaiOnePlatformResponse
    {
        return $this->client->post(ContaiOnePlatformEndpoints::USERS_BALANCE_TOPUPS, [
            'amount' => $amount,
            'currency' => $currency,
            'description' => $description,
        ]);
    }

    public function getTransactions(int $limit = 10, int $skip = 0): ContaiOnePlatformResponse
    {
        return $this->client->get(ContaiOnePlatformEndpoints::USERS_TRANSACTIONS, [
            'limit' => $limit,
            'skip' => $skip,
        ]);
    }
}
