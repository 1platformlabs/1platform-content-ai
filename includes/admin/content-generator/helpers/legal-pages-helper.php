<?php

if (!defined('ABSPATH')) exit;

class ContaiLegalPagesHelper {

    public static function register_settings(): void {
        register_setting('contai_legal_pages_settings', 'contai_cookie_notice_enabled', [
            'type' => 'string',
            'default' => '1',
            'sanitize_callback' => 'sanitize_text_field'
        ]);

        register_setting('contai_legal_pages_settings', 'contai_cookie_notice_text', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'wp_kses_post'
        ]);

        register_setting('contai_legal_pages_settings', 'contai_consent_mode', [
            'type' => 'string',
            'default' => 'opt_out',
            'sanitize_callback' => function ($value) {
                return in_array($value, ['opt_in', 'opt_out'], true) ? $value : 'opt_out';
            }
        ]);

        register_setting('contai_legal_info_settings', 'contai_legal_owner', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field'
        ]);

        register_setting('contai_legal_info_settings', 'contai_legal_address', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field'
        ]);

        register_setting('contai_legal_info_settings', 'contai_legal_activity', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field'
        ]);

        register_setting('contai_legal_info_settings', 'contai_legal_email', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_email'
        ]);
    }

    public static function get_legal_info(): array {
        $site_url = home_url();
        $domain = wp_parse_url($site_url, PHP_URL_HOST);

        return [
            'owner' => get_option('contai_legal_owner', ''),
            'address' => get_option('contai_legal_address', ''),
            'activity' => get_option('contai_legal_activity', ''),
            'email' => get_option('contai_legal_email', 'info@' . $domain)
        ];
    }

    public static function get_cookie_text(): string {
        $language = get_option('contai_site_language', 'spanish');
        $cookie_texts = [
            'english' => 'We use cookies to ensure that we give you the best user experience on our website. If you continue using this site, we will assume that you are happy with it.',
            'spanish' => 'Utilizamos cookies para asegurar que damos la mejor experiencia al usuario en nuestro sitio web. Si continúa utilizando este sitio asumiremos que está de acuerdo.'
        ];

        return $cookie_texts[$language] ?? $cookie_texts['spanish'];
    }

    /**
     * Persist the cookie-banner settings submitted by the admin form.
     *
     * The two boolean-ish fields use HTML checkbox semantics: an unchecked box
     * is simply absent from $_POST, so absence means "off". That is correct for
     * a form submission and WRONG for any programmatic caller, which is why
     * this method is now form-only — see apply_cookie_banner_defaults() for the
     * wizard path (#48).
     *
     * @param array $post_data Raw $_POST of the cookie-settings form.
     */
    public static function save_cookie_settings(array $post_data): void {
        $cookie_text = self::get_cookie_text();

        $new_text = !empty($post_data['contai_cookie_notice_text'])
            ? wp_kses_post($post_data['contai_cookie_notice_text'])
            : $cookie_text;

        update_option('contai_cookie_notice_text', $new_text);

        $enabled = isset($post_data['contai_cookie_notice_enabled']) ? '1' : '0';
        update_option('contai_cookie_notice_enabled', $enabled);

        $consent_mode = isset($post_data['contai_consent_mode']) && $post_data['contai_consent_mode'] === 'opt_in'
            ? 'opt_in'
            : 'opt_out';
        update_option('contai_consent_mode', $consent_mode);
    }

    /**
     * Set up the cookie banner from the site wizard (#48).
     *
     * The wizard used to call save_cookie_settings(array()) and its comment
     * claimed that "save_cookie_settings uses defaults when fields are absent".
     * That holds for the text field (!empty falls back to the localized
     * default) and is false for the other two, which read absence as an
     * unchecked checkbox:
     *
     *   - contai_cookie_notice_enabled was written as '0'. The renderer defaults
     *     an ABSENT option to '1' and returns early on anything else
     *     (cookie-notice-helper.php:34-39), so the step named "Cookie banner
     *     configured" was the only thing on a fresh site that could turn the
     *     banner off — and it reported success either way.
     *   - contai_consent_mode was forced back to 'opt_out' on every run, which
     *     is read live by ContaiAnalyticsTag, so re-running the wizard silently
     *     reverted an admin who had chosen 'opt_in'.
     *
     * Generating the banner means turning it ON. Consent mode is a policy
     * choice that belongs to the admin, so an existing value is preserved and
     * only an unset option takes the 'opt_out' default.
     */
    public static function apply_cookie_banner_defaults(): void {
        update_option('contai_cookie_notice_text', self::get_cookie_text());
        update_option('contai_cookie_notice_enabled', '1');

        $existing_mode = get_option('contai_consent_mode', '');
        if ($existing_mode !== 'opt_in' && $existing_mode !== 'opt_out') {
            update_option('contai_consent_mode', 'opt_out');
        }
    }
}

add_action('admin_init', [ContaiLegalPagesHelper::class, 'register_settings']);
