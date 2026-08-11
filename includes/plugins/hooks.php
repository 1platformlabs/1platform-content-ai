<?php
/**
 * WordPress hooks that keep the platform's copy of this site's plugin
 * inventory current the moment it changes, instead of waiting for the next
 * poll cycle (WPG-04).
 *
 * Any number of plugin-state changes inside a single request — a bulk
 * activate of ten plugins, for example — collapse into exactly one scheduled
 * push and, when it fires, exactly one API call: contai_plugins_maybe_schedule_push()
 * is idempotent per pending window because it checks wp_next_scheduled()
 * before ever scheduling anything.
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../services/plugins/ContaiPluginInventoryService.php';

/**
 * Schedules a single, debounced inventory push in response to a WordPress
 * plugin-state hook (activate, deactivate, delete, or an upgrader batch
 * completing). Fires 30 seconds out so a burst of hooks in one request (or
 * one bulk-action request) all land inside the same debounce window.
 */
function contai_plugins_maybe_schedule_push() {
    if (wp_next_scheduled('contai_plugins_push')) {
        return;
    }
    wp_schedule_single_event(time() + 30, 'contai_plugins_push');
}

add_action('activated_plugin', 'contai_plugins_maybe_schedule_push');
add_action('deactivated_plugin', 'contai_plugins_maybe_schedule_push');
add_action('deleted_plugin', 'contai_plugins_maybe_schedule_push');
add_action('upgrader_process_complete', 'contai_plugins_maybe_schedule_push');

add_action('contai_plugins_push', 'contai_plugins_push_callback');

function contai_plugins_push_callback() {
    ContaiPluginInventoryService::create()->push('hook');
}
