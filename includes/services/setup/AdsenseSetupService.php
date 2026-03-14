<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../helpers/site-generation.php';

class ContaiAdsenseSetupService
{
    public function setupAdsense(string $publisherId): bool
    {
        if (empty($publisherId)) {
            throw new InvalidArgumentException('AdSense publisher ID is required');
        }

        if (!preg_match('/^pub-\d+$/', $publisherId)) {
            throw new InvalidArgumentException('Invalid AdSense publisher ID format. Must be pub-XXXXX');
        }

        update_option('contai_adsense_publishers', sanitize_text_field($publisherId));

        if (function_exists('contai_generate_adsense_ads')) {
            contai_generate_adsense_ads();
        }

        return true;
    }

    public function validatePublisherId(string $publisherId): array
    {
        $errors = [];

        if (empty($publisherId)) {
            $errors[] = 'AdSense publisher ID is required';
        } elseif (!preg_match('/^pub-\d+$/', $publisherId)) {
            $errors[] = 'Invalid AdSense publisher ID format. Must be pub-XXXXX';
        }

        return $errors;
    }

    public function getPublisherId(): string
    {
        return get_option('contai_adsense_publishers', '');
    }
}
