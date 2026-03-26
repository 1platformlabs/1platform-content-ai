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
