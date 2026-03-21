<?php

if ( ! defined( 'ABSPATH' ) ) exit;

function contai_register_agent_actions_cron() {
    if ( ! wp_next_scheduled( 'contai_agent_actions_poll' ) ) {
        wp_schedule_event( time(), 'contai_every_minute', 'contai_agent_actions_poll' );
    }
}

function contai_unregister_agent_actions_cron() {
    wp_clear_scheduled_hook( 'contai_agent_actions_poll' );
}

function contai_agent_actions_poll_callback() {
    // Only run if auto-consume is enabled
    require_once dirname( __DIR__ ) . '/services/agents/ContaiAgentSettingsService.php';
    if ( ! ContaiAgentSettingsService::isAutoConsumeEnabled() ) {
        return;
    }

    require_once dirname( __DIR__ ) . '/services/agents/ContaiAgentSyncService.php';
    $sync = ContaiAgentSyncService::create();
    $sync->pollAndProcessActions();
}

add_action( 'contai_agent_actions_poll', 'contai_agent_actions_poll_callback' );
