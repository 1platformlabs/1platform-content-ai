<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../services/plugins/ContaiPluginOrderService.php';

add_action('contai_process_plugin_orders', 'contai_process_plugin_orders_callback');

function contai_register_plugin_orders_cron()
{
    // Primary runner: Action Scheduler (does not depend on HTTP traffic).
    if (function_exists('as_schedule_recurring_action') && function_exists('as_next_scheduled_action')) {
        if (!as_next_scheduled_action('contai_process_plugin_orders')) {
            as_schedule_recurring_action(time(), 60, 'contai_process_plugin_orders', [], 'contai');
        }
    }

    // Fallback runner: WP-Cron (kept for sites with sufficient HTTP traffic
    // and as a safety net when Action Scheduler is unavailable).
    if (!wp_next_scheduled('contai_process_plugin_orders')) {
        wp_schedule_event(time(), 'contai_every_minute', 'contai_process_plugin_orders');
    }
}

/**
 * Self-healing: re-register the cron event if it was lost.
 *
 * WordPress cron events (stored in wp_options) can disappear after database
 * operations, object-cache flushes, plugin auto-updates that skip the
 * activation hook, or caching-plugin interference. Checking on every `init`
 * is cheap — wp_next_scheduled reads a cached option — and guarantees the
 * plugin-orders cron is always present.
 */
add_action('init', 'contai_ensure_plugin_orders_cron');

function contai_ensure_plugin_orders_cron()
{
    contai_register_plugin_orders_cron();
}

function contai_unregister_plugin_orders_cron()
{
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('contai_process_plugin_orders');
    }
    wp_clear_scheduled_hook('contai_process_plugin_orders');
}

function contai_process_plugin_orders_callback()
{
    $service = ContaiPluginOrderService::create();
    $service->pollAndProcessOrders();
}
