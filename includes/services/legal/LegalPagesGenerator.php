<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/LegalPagesAPIClient.php';
require_once __DIR__ . '/../setup/LegalInfoService.php';

class ContaiLegalPagesGenerator
{
    private const META_SOURCE = '_contai_legal_source';
    private const META_KEY = '_contai_legal_key';
    private const META_LANG = '_contai_legal_lang';
    private const META_GENERATED_AT = '_contai_legal_generated_at';
    private const SOURCE_VALUE = 'contai_api';

    private ContaiLegalPagesAPIClient $apiClient;
    private ContaiLegalInfoService $legalInfoService;

    public function __construct(
        ?ContaiLegalPagesAPIClient $apiClient = null,
        ?ContaiLegalInfoService $legalInfoService = null
    ) {
        $this->apiClient = $apiClient ?? new ContaiLegalPagesAPIClient();
        $this->legalInfoService = $legalInfoService ?? new ContaiLegalInfoService();
    }

    public function generate(): array
    {
        $result = [
            'success' => false,
            'created' => 0,
            'skipped' => 0,
            'errors' => [],
            'warnings' => [],
            'messages' => [],
        ];

        $legalInfo = $this->legalInfoService->getLegalInfo();
        $validationErrors = $this->legalInfoService->validateLegalInfo($legalInfo);

        if (!empty($validationErrors)) {
            $result['errors'] = $validationErrors;
            return $result;
        }

        $siteTopic = get_option('contai_site_theme', '');
        $payload = [
            'legal_owner' => sanitize_text_field($legalInfo['owner']),
            'legal_email' => sanitize_email($legalInfo['email']),
            'legal_address' => sanitize_text_field($legalInfo['address']),
            'legal_activity' => sanitize_text_field($legalInfo['activity']),
            'site_topic' => sanitize_text_field($siteTopic),
        ];

        $response = $this->apiClient->generateLegalPages($payload);

        if (!$response->isSuccess()) {
            $result['errors'][] = $response->getMessage() ?? __('API request failed', '1platform-content-ai');
            return $result;
        }

        $data = $response->getData();
        $pages = $data['pages'] ?? [];
        $slugMap = $data['meta']['slug_map'] ?? [];
        $lang = $data['lang'] ?? '';

        if (empty($pages)) {
            $result['errors'][] = __('API returned no pages', '1platform-content-ai');
            return $result;
        }

        foreach ($pages as $key => $pageData) {
            $this->processPage($key, $pageData, $slugMap, $lang, $result);
        }

        $result['success'] = ($result['created'] > 0 && empty($result['errors']));

        return $result;
    }

    private function processPage(
        string $key,
        array $pageData,
        array $slugMap,
        string $lang,
        array &$result
    ): void {
        $title = $pageData['title'] ?? '';
        $content = $pageData['content'] ?? '';
        $slug = $slugMap[$key] ?? sanitize_title($title);

        if (empty($title) || empty($content)) {
            $result['warnings'][] = sprintf(
                /* translators: %s: page key identifier */
                __('Skipped page "%s": missing title or content', '1platform-content-ai'),
                esc_html($key)
            );
            $result['skipped']++;
            return;
        }

        $existingPage = get_page_by_path($slug);
        if ($existingPage) {
            $result['warnings'][] = sprintf(
                /* translators: %1$s: page title, %2$s: page slug */
                __('Page "%1$s" already exists (slug: %2$s). Not replaced.', '1platform-content-ai'),
                esc_html($title),
                esc_html($slug)
            );
            $result['skipped']++;
            return;
        }

        $postId = wp_insert_post([
            'post_title'   => sanitize_text_field($title),
            'post_name'    => sanitize_title($slug),
            'post_content' => wp_kses_post($content),
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ]);

        if (is_wp_error($postId)) {
            $result['errors'][] = sprintf(
                /* translators: %1$s: page title, %2$s: error message */
                __('Failed to create page "%1$s": %2$s', '1platform-content-ai'),
                esc_html($title),
                $postId->get_error_message()
            );
            return;
        }

        update_post_meta($postId, self::META_SOURCE, self::SOURCE_VALUE);
        update_post_meta($postId, self::META_KEY, sanitize_text_field($key));
        update_post_meta($postId, self::META_LANG, sanitize_text_field($lang));
        update_post_meta($postId, self::META_GENERATED_AT, current_time('mysql'));

        $result['created']++;
        $result['messages'][] = sprintf(
            /* translators: %s: page title */
            __('Page "%s" created successfully.', '1platform-content-ai'),
            esc_html($title)
        );
    }
}
