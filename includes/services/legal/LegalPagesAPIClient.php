<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../api/OnePlatformClient.php';
require_once __DIR__ . '/../api/OnePlatformEndpoints.php';
require_once __DIR__ . '/../config/Config.php';
require_once __DIR__ . '/../../providers/WebsiteProvider.php';

class ContaiLegalPagesAPIClient
{
    private ContaiOnePlatformClient $client;
    private ContaiWebsiteProvider $websiteProvider;

    public function __construct(
        ?ContaiOnePlatformClient $client = null,
        ?ContaiWebsiteProvider $websiteProvider = null
    ) {
        $this->client = $client ?? ContaiOnePlatformClient::create(ContaiConfig::getInstance());
        $this->websiteProvider = $websiteProvider ?? new ContaiWebsiteProvider();
    }

    public function generateLegalPages(array $legalData): ContaiOnePlatformResponse
    {
        $websiteId = $this->websiteProvider->getWebsiteId();
        if (!$websiteId) {
            return new ContaiOnePlatformResponse(
                false,
                null,
                'Website not configured. Please configure Search Console first.',
                400
            );
        }

        $endpoint = ContaiOnePlatformEndpoints::websiteLegal($websiteId);

        return $this->client->post($endpoint, $legalData);
    }
}
