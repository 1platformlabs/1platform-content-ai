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

    public static function save_cookie_settings(array $post_data): void {
        $cookie_text = self::get_cookie_text();

        $new_text = !empty($post_data['contai_cookie_notice_text'])
            ? wp_kses_post($post_data['contai_cookie_notice_text'])
            : $cookie_text;

        update_option('contai_cookie_notice_text', $new_text);

        $enabled = isset($post_data['contai_cookie_notice_enabled']) ? '1' : '0';
        update_option('contai_cookie_notice_enabled', $enabled);
    }
}

add_action('admin_init', [ContaiLegalPagesHelper::class, 'register_settings']);
