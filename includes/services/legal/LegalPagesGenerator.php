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

    // Keys match API response from legal_page_service.py / legal_pages.py
    private const REQUIRED_LEGAL_KEYS = [
        'privacy-policy' => ['es' => 'Politica de Privacidad', 'en' => 'Privacy Policy'],
        'cookie-policy'  => ['es' => 'Politica de Cookies',    'en' => 'Cookie Policy'],
        'legal-policy'   => ['es' => 'Aviso Legal',            'en' => 'Legal Notice'],
        'about-me'       => ['es' => 'Sobre mi',               'en' => 'About Me'],
        'contact'        => ['es' => 'Contacto',               'en' => 'Contact'],
    ];

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

        $this->ensureRequiredLegalPages($lang, $result);

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
        if (!$existingPage) {
            // Check if page exists in trash
            $trashed = get_posts([
                'post_type'   => 'page',
                'name'        => $slug,
                'post_status' => 'trash',
                'numberposts' => 1,
            ]);
            if (!empty($trashed)) {
                wp_untrash_post($trashed[0]->ID);
                $existingPage = $trashed[0];
                error_log("[ContAI] Legal page '{$key}' restored from trash (slug: {$slug})");
            }
        }
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

    private function ensureRequiredLegalPages(string $lang, array &$result): void
    {
        $lang_key = ($lang === 'es' || $lang === 'spanish') ? 'es' : 'en';

        foreach (self::REQUIRED_LEGAL_KEYS as $key => $titles) {
            // Look up by meta key (reliable regardless of slug)
            $existing = get_posts([
                'post_type'   => 'page',
                'meta_key'    => self::META_KEY,
                'meta_value'  => $key,
                'post_status' => ['publish', 'draft', 'trash'],
                'numberposts' => 1,
            ]);

            if (!empty($existing)) {
                if ($existing[0]->post_status === 'trash') {
                    wp_untrash_post($existing[0]->ID);
                    error_log("[ContAI] Legal page '{$key}' was in trash — restored");
                }
                continue;
            }

            $title = $titles[$lang_key];
            $slug = sanitize_title($title);

            $post_id = wp_insert_post([
                'post_title'   => sanitize_text_field($title),
                'post_name'    => $slug,
                'post_content' => $this->generateFallbackContent($key, $title),
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ]);

            if ($post_id && !is_wp_error($post_id)) {
                update_post_meta($post_id, self::META_SOURCE, self::SOURCE_VALUE);
                update_post_meta($post_id, self::META_KEY, sanitize_text_field($key));
                update_post_meta($post_id, self::META_LANG, sanitize_text_field($lang));
                update_post_meta($post_id, self::META_GENERATED_AT, current_time('mysql'));

                $result['created']++;
                $result['messages'][] = sprintf(
                    __('Legal page "%s" was missing — created fallback.', '1platform-content-ai'),
                    esc_html($title)
                );
                error_log("[ContAI] Legal page '{$key}' was missing — created fallback (post_id: {$post_id})");
            }
        }
    }

    private function generateFallbackContent(string $key, string $title): string
    {
        $owner = sanitize_text_field(get_option('contai_legal_owner', ''));
        $email = sanitize_email(get_option('contai_legal_email', ''));

        $content = '<h1>' . esc_html($title) . '</h1>';

        switch ($key) {
            case 'cookie-policy':
                $content .= '<p>' . esc_html__('This website uses cookies to ensure the best user experience.', '1platform-content-ai') . '</p>';
                $content .= '<h2>' . esc_html__('What are cookies?', '1platform-content-ai') . '</h2>';
                $content .= '<p>' . esc_html__('Cookies are small text files stored on your device when you visit a website.', '1platform-content-ai') . '</p>';
                $content .= '<h2>' . esc_html__('Types of cookies we use', '1platform-content-ai') . '</h2>';
                $content .= '<p>' . esc_html__('Necessary cookies: required for the website to function properly.', '1platform-content-ai') . '</p>';
                $content .= '<p>' . esc_html__('Analytics cookies: help us understand how visitors interact with the website.', '1platform-content-ai') . '</p>';
                $content .= '<h2>' . esc_html__('How to disable cookies', '1platform-content-ai') . '</h2>';
                $content .= '<p>' . esc_html__('You can configure your browser to reject cookies. Please note that some features may not work correctly.', '1platform-content-ai') . '</p>';
                break;

            case 'privacy-policy':
                $content .= '<p>' . sprintf(esc_html__('The owner of this website, %s, is committed to protecting your privacy.', '1platform-content-ai'), esc_html($owner)) . '</p>';
                if ($email) {
                    $content .= '<p>' . sprintf(esc_html__('Contact: %s', '1platform-content-ai'), esc_html($email)) . '</p>';
                }
                break;

            case 'legal-policy':
                $content .= '<p>' . sprintf(esc_html__('This website is owned by %s.', '1platform-content-ai'), esc_html($owner)) . '</p>';
                if ($email) {
                    $content .= '<p>' . sprintf(esc_html__('Contact: %s', '1platform-content-ai'), esc_html($email)) . '</p>';
                }
                break;

            case 'contact':
                if ($email) {
                    $content .= '<p>' . sprintf(esc_html__('Email: %s', '1platform-content-ai'), esc_html($email)) . '</p>';
                }
                break;

            case 'about-me':
                $content .= '<p>' . sprintf(esc_html__('Welcome! This site is managed by %s.', '1platform-content-ai'), esc_html($owner)) . '</p>';
                break;
        }

        return $content;
    }
}
