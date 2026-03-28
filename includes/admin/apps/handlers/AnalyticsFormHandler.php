<?php

if (!defined('ABSPATH')) exit;

/**
 * AJAX handler for Google Analytics integration.
 *
 * Handles connect/disconnect, OAuth flow, setup, and status polling.
 */
class ContaiAnalyticsFormHandler
{
    public function __construct()
    {
        add_action('wp_ajax_contai_analytics_connect', [$this, 'handleConnect']);
        add_action('wp_ajax_contai_analytics_disconnect', [$this, 'handleDisconnect']);
        add_action('wp_ajax_contai_analytics_get_oauth_url', [$this, 'handleGetOauthUrl']);
        add_action('wp_ajax_contai_analytics_check_oauth', [$this, 'handleCheckOauth']);
        add_action('wp_ajax_contai_analytics_setup', [$this, 'handleSetup']);
        add_action('wp_ajax_contai_analytics_poll_status', [$this, 'handlePollStatus']);
    }

    public function handleConnect(): void
    {
        check_ajax_referer('contai_analytics_connect');
        if (!current_user_can('manage_options')) wp_send_json_error('Insufficient permissions');

        $mid = isset($_POST['measurement_id']) ? sanitize_text_field(wp_unslash($_POST['measurement_id'])) : '';
        if (!preg_match('/^G-[A-Z0-9]{8,12}$/', $mid)) wp_send_json_error('Invalid Measurement ID');

        update_option('1platform_ga4_measurement_id', $mid, false);
        wp_send_json_success('Connected');
    }

    public function handleDisconnect(): void
    {
        check_ajax_referer('contai_analytics_disconnect');
        if (!current_user_can('manage_options')) wp_send_json_error('Insufficient permissions');

        delete_option('1platform_ga4_measurement_id');
        wp_send_json_success('Disconnected');
    }

    public function handleGetOauthUrl(): void
    {
        check_ajax_referer('contai_analytics_oauth');
        if (!current_user_can('manage_options')) wp_send_json_error('Insufficient permissions');

        $website_id = isset($_POST['website_id']) ? sanitize_text_field(wp_unslash($_POST['website_id'])) : '';
        if (empty($website_id)) wp_send_json_error('Website ID required');

        $client = ContaiOnePlatformClient::create();
        $response = $client->get(ContaiOnePlatformEndpoints::ANALYTICS_OAUTH_AUTHORIZE . '?website_id=' . urlencode($website_id));

        if ($response->isSuccess()) {
            wp_send_json_success($response->getData());
        } else {
            wp_send_json_error(['message' => $response->getMessage()]);
        }
    }

    public function handleCheckOauth(): void
    {
        check_ajax_referer('contai_analytics_oauth');
        if (!current_user_can('manage_options')) wp_send_json_error('Insufficient permissions');

        $website_id = isset($_POST['website_id']) ? sanitize_text_field(wp_unslash($_POST['website_id'])) : '';
        if (empty($website_id)) wp_send_json_error('Website ID required');

        $client = ContaiOnePlatformClient::create();
        $response = $client->get(ContaiOnePlatformEndpoints::ANALYTICS_OAUTH_STATUS . '?website_id=' . urlencode($website_id));

        if ($response->isSuccess()) {
            wp_send_json_success($response->getData());
        } else {
            wp_send_json_error(['message' => $response->getMessage()]);
        }
    }

    public function handleSetup(): void
    {
        check_ajax_referer('contai_analytics_setup');
        if (!current_user_can('manage_options')) wp_send_json_error('Insufficient permissions');

        $website_id = isset($_POST['website_id']) ? sanitize_text_field(wp_unslash($_POST['website_id'])) : '';
        if (empty($website_id)) wp_send_json_error('Website ID required');

        $client = ContaiOnePlatformClient::create();
        $response = $client->post(ContaiOnePlatformEndpoints::ANALYTICS_SETUP, [
            'website_id' => $website_id,
            'account_id' => 'accounts/0',
        ]);

        if ($response->isSuccess()) {
            $data = $response->getData();
            if (!empty($data['measurement_id'])) {
                update_option('1platform_ga4_measurement_id', sanitize_text_field($data['measurement_id']), false);
            }
            wp_send_json_success($data);
        } else {
            wp_send_json_error(['message' => $response->getMessage()]);
        }
    }

    public function handlePollStatus(): void
    {
        check_ajax_referer('contai_analytics_setup');
        if (!current_user_can('manage_options')) wp_send_json_error('Insufficient permissions');

        $website_id = isset($_POST['website_id']) ? sanitize_text_field(wp_unslash($_POST['website_id'])) : '';
        if (empty($website_id)) wp_send_json_error('Website ID required');

        $client = ContaiOnePlatformClient::create();
        $response = $client->get(ContaiOnePlatformEndpoints::ANALYTICS_STATUS . '?website_id=' . urlencode($website_id));

        if ($response->isSuccess()) {
            $data = $response->getData();
            if (!empty($data['measurement_id']) && ($data['status'] ?? '') === 'active') {
                update_option('1platform_ga4_measurement_id', sanitize_text_field($data['measurement_id']), false);
            }
            wp_send_json_success($data);
        } else {
            wp_send_json_error(['message' => $response->getMessage()]);
        }
    }
}
