<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../database/models/Keyword.php';

class ContaiPostMetadataBuilder {

    public function buildFromKeyword(ContaiKeyword $keyword, array $generation_params): array {
        $metadata = [
            '_keyword_id' => $keyword->getId(),
            '_keyword' => $keyword->getKeyword(),
            '_keyword_volume' => $keyword->getVolume(),
            '_content_lang' => $generation_params['lang'] ?? 'en',
            '_content_country' => $generation_params['country'] ?? 'us',
            '_image_provider' => $generation_params['image_provider'] ?? 'pexels',
        ];

        // Analytics integration meta (used by GA4 custom dimensions).
        $metadata['_1platform_ai_generated'] = '1';
        $metadata['_1platform_keyword'] = $keyword->getKeyword() ?? '';
        $metadata['_1platform_cluster'] = '';

        return $metadata;
    }
}
