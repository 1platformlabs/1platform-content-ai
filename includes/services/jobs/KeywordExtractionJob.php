<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/JobInterface.php';
require_once __DIR__ . '/../keyword/KeywordExtractorService.php';

class ContaiKeywordExtractionJob implements ContaiJobInterface
{
    const TYPE = 'keyword_extraction';

    private ContaiKeywordExtractorService $extractor_service;

    public function __construct()
    {
        $this->extractor_service = ContaiKeywordExtractorService::create();
    }

    public function handle(array $payload)
    {
        $domain = $payload['domain'] ?? '';
        $country = $payload['country'] ?? '';
        $lang = $payload['lang'] ?? '';

        if (empty($domain) || empty($country) || empty($lang)) {
            return [
                'success' => false,
                'error' => 'Missing required parameters: domain, country, or lang'
            ];
        }

        $result = $this->extractor_service->extractAndSaveKeywords($domain, $country, $lang);

        if ($result->isSuccess()) {
            return [
                'success' => true,
                'saved_count' => $result->getSavedCount(),
                'skipped_count' => $result->getSkippedCount(),
                'total_count' => $result->getTotalCount(),
                'domain' => $domain
            ];
        }

        return [
            'success' => false,
            'error' => $result->getErrorMessage(),
            'domain' => $domain
        ];
    }

    public function getType()
    {
        return self::TYPE;
    }
}
