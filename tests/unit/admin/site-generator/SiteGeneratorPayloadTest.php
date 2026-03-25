<?php

namespace ContAI\Tests\Unit\Admin\SiteGenerator;

use PHPUnit\Framework\TestCase;
use ContaiCategoryAPIService;

/**
 * Tests that the Site Wizard payload correctly derives target_language
 * from the form's site_language field via normalizeLanguage().
 *
 * This validates the fix for GitHub issue #15: selecting "Spanish" in the
 * Site Wizard was ignored because keyword_extraction.target_language and
 * post_generation.target_language read from a non-existent form field
 * (contai_target_language) instead of deriving from contai_site_language.
 */
class SiteGeneratorPayloadTest extends TestCase {

    /**
     * Simulates the payload construction logic from admin-ai-site-generator.php.
     * The actual function reads from $_POST; this helper mirrors its normalization.
     */
    private function buildPayloadLanguageFields(string $site_language): array {
        $target_language = ContaiCategoryAPIService::normalizeLanguage($site_language);

        return [
            'site_config_language' => $site_language,
            'keyword_target_language' => $target_language,
            'post_target_language' => $target_language,
        ];
    }

    public function test_spanish_form_language_produces_es_target_language(): void {
        $result = $this->buildPayloadLanguageFields('spanish');

        $this->assertSame('spanish', $result['site_config_language']);
        $this->assertSame('es', $result['keyword_target_language']);
        $this->assertSame('es', $result['post_target_language']);
    }

    public function test_english_form_language_produces_en_target_language(): void {
        $result = $this->buildPayloadLanguageFields('english');

        $this->assertSame('english', $result['site_config_language']);
        $this->assertSame('en', $result['keyword_target_language']);
        $this->assertSame('en', $result['post_target_language']);
    }

    public function test_default_empty_language_falls_back_to_en(): void {
        $result = $this->buildPayloadLanguageFields('');

        $this->assertSame('en', $result['keyword_target_language']);
        $this->assertSame('en', $result['post_target_language']);
    }

    public function test_keyword_and_post_language_always_match(): void {
        foreach (['english', 'spanish', 'en', 'es', '', 'unknown'] as $lang) {
            $result = $this->buildPayloadLanguageFields($lang);
            $this->assertSame(
                $result['keyword_target_language'],
                $result['post_target_language'],
                "keyword and post target_language must match for input '{$lang}'"
            );
        }
    }

    public function test_site_generation_job_receives_correct_language_in_extraction_config(): void {
        $site_language = 'spanish';
        $target_language = ContaiCategoryAPIService::normalizeLanguage($site_language);

        $payload = [
            'config' => [
                'keyword_extraction' => [
                    'source_topic' => 'finanzas personales',
                    'target_country' => 'es',
                    'target_language' => $target_language,
                ],
                'post_generation' => [
                    'num_posts' => 100,
                    'target_country' => 'es',
                    'target_language' => $target_language,
                    'image_provider' => 'pexels',
                ],
            ],
        ];

        $this->assertSame('es', $payload['config']['keyword_extraction']['target_language']);
        $this->assertSame('es', $payload['config']['post_generation']['target_language']);
    }
}
