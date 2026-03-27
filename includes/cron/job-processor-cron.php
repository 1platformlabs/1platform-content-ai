<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../services/jobs/JobProcessor.php';

add_action('contai_process_job_queue', 'contai_process_job_queue_callback');

function contai_register_job_processor_cron()
{
    if (!wp_next_scheduled('contai_process_job_queue')) {
        wp_schedule_event(time(), 'contai_every_minute', 'contai_process_job_queue');
    }
}

/**
 * Self-healing: re-register the cron event if it was lost.
 *
 * WordPress cron events (stored in wp_options) can disappear after database
 * operations, object-cache flushes, plugin auto-updates that skip the
 * activation hook, or caching-plugin interference.  Checking on every `init`
 * is cheap — wp_next_scheduled reads a cached option — and guarantees the
 * job-processor cron is always present.
 */
add_action('init', 'contai_ensure_job_processor_cron');

function contai_ensure_job_processor_cron()
{
    contai_register_job_processor_cron();
}

/**
 * Schedule an immediate one-shot cron event and kick wp-cron so that
 * newly-enqueued jobs begin processing without waiting for the next
 * scheduled cycle (up to 60 s away).
 */
function contai_trigger_immediate_job_processing()
{
    if (!wp_next_scheduled('contai_process_job_queue')) {
        contai_register_job_processor_cron();
    }

    spawn_cron();
}

function contai_unregister_job_processor_cron()
{
    wp_clear_scheduled_hook('contai_process_job_queue');
}

function contai_process_job_queue_callback()
{
    $processor = new ContaiJobProcessor();
    $processor->processQueue();
}

add_filter('cron_schedules', 'contai_add_every_minute_schedule');

function contai_add_every_minute_schedule($schedules)
{
    $schedules['contai_every_minute'] = [
        'interval' => 60,
        'display' => __('Every Minute', '1platform-content-ai')
    ];
    return $schedules;
}
