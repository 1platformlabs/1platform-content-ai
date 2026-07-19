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
            $existingPage = $this->findTrashedPageBySlug($slug);
            if ($existingPage) {
                wp_untrash_post($existingPage->ID);
                error_log("[ContAI] Legal page '{$key}' restored from trash (slug: {$slug})");
            }
        }
        if ($existingPage) {
            // Adopt it instead of walking away. The footer menu selects strictly
            // by the '_contai_legal_source' meta (site-generation.php), so a page
            // skipped here without that meta could never be linked — the wizard
            // reported "already exists" and the site ended up with a legal page
            // and no way to reach it (#48).
            //
            // This is not an edge case: get_page_by_path() has no post_status
            // filter (wp-includes/post.php, the SELECT lists only post_name and
            // post_type), and a stock WordPress install ships a DRAFT page whose
            // slug is 'privacy-policy' (wp-admin/includes/upgrade.php:399,404).
            // So on an English stock install the very first legal page the
            // wizard tried to create hit this branch, was skipped, was never
            // linked, and was then duplicated as 'privacy-policy-2' by
            // ensureRequiredLegalPages().
            //
            // The content is still never replaced — the owner's text stands.
            $this->adoptExistingPage($existingPage, $key, $lang, $result);
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

    /**
     * Find a trashed page by the slug it had BEFORE it was trashed.
     *
     * The previous lookup queried get_posts(['name' => $slug, 'post_status' =>
     * 'trash']) and could never match anything: core renames a post's slug when
     * it is trashed — wp_trash_post() reaches wp_add_trashed_suffix_to_post_
     * name_for_post() (wp-includes/post.php:4810, :8390-8403), which writes
     * '<slug>__trashed' and stashes the original in the '_wp_desired_post_slug'
     * meta — while WP_Query matches 'name' exactly. So 'privacy-policy' never
     * equalled 'privacy-policy__trashed' and the whole trash-recovery branch
     * was dead code (#48).
     *
     * Both of core's records are checked: the meta first, because it survives
     * the truncation _truncate_post_slug() applies to long slugs.
     */
    private function findTrashedPageBySlug(string $slug): ?object
    {
        $byMeta = get_posts([
            'post_type'   => 'page',
            'post_status' => 'trash',
            'meta_key'    => '_wp_desired_post_slug',
            'meta_value'  => $slug,
            'numberposts' => 1,
        ]);

        if (!empty($byMeta)) {
            return $byMeta[0];
        }

        $byName = get_posts([
            'post_type'   => 'page',
            'post_status' => 'trash',
            'name'        => $slug . '__trashed',
            'numberposts' => 1,
        ]);

        return !empty($byName) ? $byName[0] : null;
    }

    /**
     * Take ownership of a page that already existed, without touching its text.
     *
     * Two things have to be true for a legal page to actually work, and neither
     * was for a skipped page (#48):
     *
     *   - It must carry '_contai_legal_source'. The footer menu builder selects
     *     candidates by that meta alone, so a page without it is unlinkable no
     *     matter how correct its slug is.
     *   - It must be published. The same builder filters on 'post_status' =>
     *     'publish', and a page restored from the trash comes back as a DRAFT —
     *     core's wp_untrash_post() defaults the restored status to 'draft'
     *     rather than the pre-trash one (wp-includes/post.php, $new_status).
     *     The wizard never published it afterwards.
     *
     * Publishing someone's draft is a real change, so it is recorded durably
     * rather than done silently.
     */
    private function adoptExistingPage(object $page, string $key, string $lang, array &$result): void
    {
        update_post_meta($page->ID, self::META_SOURCE, self::SOURCE_VALUE);
        update_post_meta($page->ID, self::META_KEY, sanitize_text_field($key));
        update_post_meta($page->ID, self::META_LANG, sanitize_text_field($lang));

        $status = $page->post_status ?? '';

        $result['warnings'][] = sprintf(
            /* translators: %1$s: page title, %2$s: page slug */
            __('Page "%1$s" already exists (slug: %2$s). Content left untouched; linked into the footer.', '1platform-content-ai'),
            esc_html($page->post_title ?? ''),
            esc_html($page->post_name ?? '')
        );

        if ($status === 'publish') {
            return;
        }

        wp_update_post([
            'ID'          => $page->ID,
            'post_status' => 'publish',
        ]);

        if (function_exists('contai_record_site_warning')) {
            contai_record_site_warning(
                'legal page adopted',
                sprintf(
                    "existing '%s' page (id %d) was %s and has been published so it can appear in the footer",
                    $key,
                    $page->ID,
                    $status !== '' ? $status : 'unpublished'
                )
            );
        }
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
                if (($existing[0]->post_status ?? '') === 'trash') {
                    // wp_untrash_post() first: it is what restores the slug core
                    // renamed to '<slug>__trashed', from the '_wp_desired_post_slug'
                    // meta it stashed.
                    wp_untrash_post($existing[0]->ID);
                }
                if (($existing[0]->post_status ?? '') !== 'publish') {
                    // ...but restoring yields a DRAFT, and the footer menu only
                    // selects published pages, so stopping at wp_untrash_post()
                    // left the page invisible (#48).
                    $this->adoptExistingPage($existing[0], $key, $lang, $result);
                }
                continue;
            }

            $title = $titles[$lang_key];
            $slug = sanitize_title($title);

            // A page can exist under this slug without carrying our meta — the
            // stock WordPress 'privacy-policy' draft is the common case — and
            // the meta lookup above cannot see it. Creating anyway produced a
            // second page at 'privacy-policy-2' while the original stayed
            // behind, so adopt it instead (#48).
            $existingBySlug = get_page_by_path($slug) ?: $this->findTrashedPageBySlug($slug);
            if ($existingBySlug) {
                if ($existingBySlug->post_status === 'trash') {
                    wp_untrash_post($existingBySlug->ID);
                }
                $this->adoptExistingPage($existingBySlug, $key, $lang, $result);
                continue;
            }

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
            } else {
                // There was no else at all: a required legal page could fail to
                // be created and nothing was appended to errors, nothing logged,
                // nothing recorded. The site simply had no privacy policy and
                // the wizard reported success (#48).
                $message = is_wp_error($post_id)
                    ? $post_id->get_error_message()
                    : __('wp_insert_post returned no id', '1platform-content-ai');

                $result['errors'][] = sprintf(
                    /* translators: %1$s: page title, %2$s: error message */
                    __('Failed to create required legal page "%1$s": %2$s', '1platform-content-ai'),
                    esc_html($title),
                    $message
                );

                if (function_exists('contai_record_site_warning')) {
                    contai_record_site_warning(
                        'legal page missing',
                        sprintf("required page '%s' could not be created: %s", $key, $message)
                    );
                }
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
