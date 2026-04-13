<?php
if (!defined('ABSPATH')) exit;

/**
 * Injects the GA4 tag into wp_head with GDPR Consent Mode v2.
 * Tag is built from validated measurement_id — never stores/echoes raw HTML from external APIs.
 */
class OnePlatform_Analytics_Tag {

    public function init(): void {
        add_action('wp_head', [$this, 'inject_gtag'], 1);
        add_action('wp_footer', [$this, 'send_content_dimensions']);
    }

    /**
     * Inject GA4 tag with Consent Mode v2.
     */
    public function inject_gtag(): void {
        $measurement_id = get_option('1platform_ga4_measurement_id', '');
        if (!$measurement_id) {
            return;
        }

        // Validate measurement_id format (anti-XSS)
        if (!preg_match('/^G-[A-Z0-9]{8,12}$/', $measurement_id)) {
            return;
        }

        $escaped_id = esc_attr($measurement_id);

        $consent_state = $this->resolve_consent_state();
        ?>
        <!-- Google tag (gtag.js) - Managed by 1Platform -->
        <script>
          window.dataLayer = window.dataLayer || [];
          function gtag(){dataLayer.push(arguments);}
          gtag('consent', 'default', {
            'analytics_storage': '<?php echo esc_js($consent_state); ?>',
            'ad_storage': '<?php echo esc_js($consent_state); ?>'
          });
        </script>
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo $escaped_id; ?>"></script>
        <script>
          gtag('js', new Date());
          gtag('config', '<?php echo $escaped_id; ?>');
        </script>
        <?php
    }

    /**
     * Resolve consent state from cookie + admin config + filter.
     *
     * Logic:
     * 1. If the `1platform_analytics_consent_granted` filter returns true,
     *    external consent plugins (CookieYes, Complianz) override → granted.
     * 2. Read the cookie_notice_accepted cookie (whitelist-validated).
     *    - 'true'  → user accepted → granted
     *    - 'false' → user rejected → denied
     * 3. If no cookie (first visit), use admin-configured default:
     *    - 'opt_out' (default) → granted (tracks by default, user can reject)
     *    - 'opt_in'            → denied  (GDPR-strict, requires explicit accept)
     *
     * @return string 'granted' or 'denied'
     */
    private function resolve_consent_state(): string {
        if (apply_filters('1platform_analytics_consent_granted', false)) {
            return 'granted';
        }

        $cookie_value = isset($_COOKIE['cookie_notice_accepted'])
            ? sanitize_text_field(wp_unslash($_COOKIE['cookie_notice_accepted']))
            : '';

        if ($cookie_value === 'true') {
            return 'granted';
        }
        if ($cookie_value === 'false') {
            return 'denied';
        }

        $consent_mode = get_option('contai_consent_mode', 'opt_out');
        return $consent_mode === 'opt_in' ? 'denied' : 'granted';
    }

    /**
     * Send custom dimensions for AI-generated posts.
     */
    public function send_content_dimensions(): void {
        if (!is_singular('post')) {
            return;
        }

        $measurement_id = get_option('1platform_ga4_measurement_id', '');
        if (!$measurement_id || !preg_match('/^G-[A-Z0-9]{8,12}$/', $measurement_id)) {
            return;
        }

        $post_id = get_the_ID();
        $is_ai = get_post_meta($post_id, '_1platform_ai_generated', true);

        $keyword = mb_substr(sanitize_text_field(
            get_post_meta($post_id, '_1platform_keyword', true)
        ), 0, 200);
        $cluster = mb_substr(sanitize_text_field(
            get_post_meta($post_id, '_1platform_cluster', true)
        ), 0, 200);

        $source = $is_ai ? 'ai' : 'manual';
        ?>
        <script>
            gtag('event', 'content_view', {
                content_source: '<?php echo esc_js($source); ?>',
                target_keyword: '<?php echo esc_js($keyword); ?>',
                content_cluster: '<?php echo esc_js($cluster); ?>',
                op_content_type: '<?php echo esc_js(get_post_type() ?: 'article'); ?>'
            });
        </script>
        <?php
    }
}
