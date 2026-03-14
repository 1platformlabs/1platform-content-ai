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

    public function createTransaction(float $amount, string $currency, string $description): ContaiOnePlatformResponse
    {
        return $this->client->post(ContaiOnePlatformEndpoints::USERS_TRANSACTIONS, [
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
