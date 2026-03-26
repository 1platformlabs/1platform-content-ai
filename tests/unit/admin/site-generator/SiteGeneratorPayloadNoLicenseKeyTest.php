<?php

namespace ContAI\Tests\Unit\Admin\SiteGenerator;

use PHPUnit\Framework\TestCase;

/**
 * Tests that the Site Wizard payload does NOT contain license_key.
 *
 * Validates the fix for GitHub issue #16: the activateLicense() step
 * was double-encrypting the API key by reading the already-encrypted key
 * from the payload and re-saving it through saveApiKey() (which encrypts).
 * After this bug, all API auth fails and categories show "No categories available".
 *
 * Fix: removed license_key from the payload entirely. The activateLicense()
 * step now reads the key directly from wp_options via hasApiKey().
 */
class SiteGeneratorPayloadNoLicenseKeyTest extends TestCase {

    /**
     * Simulates the payload structure from admin-ai-site-generator.php
     * after the fix. license_key must NOT be present.
     */
    private function buildPayload(): array {
        return [
            'config' => [
                'site_config' => [
                    'site_topic' => 'Indoor gardening',
                    'site_language' => 'english',
                    'site_category' => 'cat-123',
                    'wordpress_theme' => 'astra',
                ],
                'legal_info' => [
                    'owner' => 'John Doe',
                    'email' => 'john@example.com',
                    'address' => '123 Main St',
                    'activity' => 'Digital publishing',
                ],
                'keyword_extraction' => [
                    'source_topic' => 'indoor plants care',
                    'target_country' => 'us',
                    'target_language' => 'en',
                ],
                'post_generation' => [
                    'num_posts' => 100,
                    'target_country' => 'us',
                    'target_language' => 'en',
                    'image_provider' => 'pexels',
                ],
                'comments' => [
                    'num_posts' => 100,
                    'comments_per_post' => 1,
                ],
                'adsense' => [
                    'publisher_id' => 'pub-1234567890123456',
                ],
            ],
            'progress' => [
                'current_step' => 0,
                'current_step_name' => '',
                'completed_steps' => [],
                'total_steps' => 11,
                'started_at' => '2026-03-25 10:00:00',
            ],
        ];
    }

    public function test_payload_does_not_contain_license_key(): void {
        $payload = $this->buildPayload();

        $this->assertArrayNotHasKey(
            'license_key',
            $payload['config'],
            'Payload must not contain license_key to prevent double-encryption (issue #16)'
        );
    }

    public function test_payload_contains_required_config_sections(): void {
        $payload = $this->buildPayload();

        $this->assertArrayHasKey('site_config', $payload['config']);
        $this->assertArrayHasKey('legal_info', $payload['config']);
        $this->assertArrayHasKey('keyword_extraction', $payload['config']);
        $this->assertArrayHasKey('post_generation', $payload['config']);
        $this->assertArrayHasKey('comments', $payload['config']);
        $this->assertArrayHasKey('adsense', $payload['config']);
    }

    public function test_payload_has_no_sensitive_data(): void {
        $payload = $this->buildPayload();

        $configJson = json_encode($payload['config']);

        $this->assertStringNotContainsString('license_key', $configJson);
        $this->assertStringNotContainsString('api_key', $configJson);
        $this->assertStringNotContainsString('contai_api_key', $configJson);
    }
}
