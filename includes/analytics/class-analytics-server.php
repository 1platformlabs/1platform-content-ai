<?php
if (!defined('ABSPATH')) exit;

/**
 * Sends server-side events via the 1Platform API (Measurement Protocol proxy).
 */
class OnePlatform_Analytics_Server {

    private function send_event(string $event_name, array $params = []): void {
        // Validate event name
        $allowed = ['content_published', 'content_updated', 'comment_received', 'seo_action', 'keyword_research'];
        if (!in_array($event_name, $allowed, true)) {
            return;
        }

        // Validate params
        $params = array_slice($params, 0, 25);
        foreach ($params as $key => $value) {
            $params[$key] = mb_substr(sanitize_text_field((string) $value), 0, 100);
        }

        // Rate limit: max 60 events/hour
        $count_key = 'contai_mp_event_count_' . gmdate('YmdH');
        $count = (int) get_transient($count_key);
        if ($count >= 60) {
            error_log('[1Platform Analytics] Server event rate limit reached — event dropped: ' . $event_name);
            return;
        }
        set_transient($count_key, $count + 1, HOUR_IN_SECONDS);

        $websiteProvider = new ContaiWebsiteProvider();
        $website_id = $websiteProvider->getWebsiteId();
        if (!$website_id) {
            return;
        }

        // Idempotency key: deduplicate retries within the same hour
        $event_id = md5($event_name . '_' . ($params['post_id'] ?? '') . '_' . gmdate('YmdH'));
        $params['event_id'] = $event_id;

        // Send via API proxy
        $client = ContaiOnePlatformClient::create();
        $result = $client->post(ContaiOnePlatformEndpoints::ANALYTICS_MP_EVENT, [
            'website_id' => $website_id,
            'event_name' => $event_name,
            'params'     => $params,
        ]);

        if (!$result->isSuccess()) {
            error_log('[1Platform Analytics] MP event failed: ' . ($result->getMessage() ?? 'Unknown error'));
        }
    }

    public function on_publish_post(int $post_id, \WP_Post $post): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if ($post->post_status !== 'publish') {
            return;
        }

        $is_ai = get_post_meta($post_id, '_1platform_ai_generated', true);
        $this->send_event('content_published', [
            'content_source'  => $is_ai ? 'ai' : 'manual',
            'target_keyword'  => get_post_meta($post_id, '_1platform_keyword', true),
            'content_cluster' => get_post_meta($post_id, '_1platform_cluster', true),
            'content_type'    => $post->post_type,
            'post_id'         => (string) $post_id,
        ]);
    }

    public function on_update_post(int $post_id): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        $this->send_event('content_updated', [
            'post_id'        => (string) $post_id,
            'content_source' => get_post_meta($post_id, '_1platform_ai_generated', true) ? 'ai' : 'manual',
        ]);
    }

    public function on_comment_received(int $comment_id, $approved): void {
        if ($approved !== 1 && $approved !== '1') {
            return;
        }

        $comment = get_comment($comment_id);
        if (!$comment) {
            return;
        }

        $this->send_event('comment_received', [
            'post_id'        => (string) $comment->comment_post_ID,
            'comment_type'   => $comment->comment_type ?: 'comment',
            'content_source' => get_post_meta($comment->comment_post_ID, '_1platform_ai_generated', true) ? 'ai' : 'manual',
        ]);
    }

    public function on_seo_action(string $action, string $url): void {
        $this->send_event('seo_action', [
            'action_type' => sanitize_text_field($action),
            'target_url'  => esc_url_raw($url),
        ]);
    }

    public function on_keyword_research(string $keyword, int $results_count): void {
        $this->send_event('keyword_research', [
            'keyword'       => sanitize_text_field($keyword),
            'results_count' => (string) $results_count,
        ]);
    }

    /**
     * Register WordPress hooks.
     */
    public function init(): void {
        add_action('transition_post_status', function ($new_status, $old_status, $post) {
            if ($new_status === 'publish' && $old_status !== 'publish') {
                $this->on_publish_post($post->ID, $post);
            } elseif ($new_status === 'publish' && $old_status === 'publish') {
                $this->on_update_post($post->ID);
            }
        }, 10, 3);

        add_action('comment_post', [$this, 'on_comment_received'], 10, 2);
    }
}
