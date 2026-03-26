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
        // Fail-fast credit check before consuming API resources
        require_once __DIR__ . '/../billing/CreditGuard.php';
        $creditGuard = new ContaiCreditGuard();
        $creditCheck = $creditGuard->validateCredits();

        if (!$creditCheck['has_credits']) {
            return [
                'success' => false,
                'error' => $creditCheck['message'],
            ];
        }

        $topic = $payload['topic'] ?? '';
        $domain = $payload['domain'] ?? '';
        $country = $payload['country'] ?? '';
        $lang = $payload['lang'] ?? '';

        // Support both topic-based (new) and domain-based (legacy) payloads
        $source = !empty($topic) ? $topic : $domain;

        if (empty($source) || empty($country) || empty($lang)) {
            return [
                'success' => false,
                'error' => 'Missing required parameters: topic/domain, country, or lang'
            ];
        }

        $result = !empty($topic)
            ? $this->extractor_service->extractByTopicAndSave($topic, $country, $lang)
            : $this->extractor_service->extractAndSaveKeywords($domain, $country, $lang);

        if ($result->isSuccess()) {
            return [
                'success' => true,
                'saved_count' => $result->getSavedCount(),
                'skipped_count' => $result->getSkippedCount(),
                'total_count' => $result->getTotalCount(),
                'source' => $source,
            ];
        }

        return [
            'success' => false,
            'error' => $result->getErrorMessage(),
            'source' => $source,
        ];
    }

    public function getType()
    {
        return self::TYPE;
    }
}
