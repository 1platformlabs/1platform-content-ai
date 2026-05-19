<?php
/**
 * REST API controller for queue diagnostics and manual recovery.
 *
 * Exposes POST /wp-json/contai/v1/queue/run which lets a site administrator
 * force a job-processor tick without waiting for the WP-Cron event.
 * Required for sites with low HTTP traffic where WP-Cron rarely fires.
 *
 * @package OnePlatformContentAI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/metrics/QueueHealthService.php';

class ContaiQueueRestController {

    private string $namespace = 'contai/v1';

    private const RATE_LIMIT_SECONDS = 10;
    private const RATE_LIMIT_OPTION_PREFIX = 'contai_queue_run_user_';

    private ContaiQueueHealthService $health_service;

    public function __construct( ?ContaiQueueHealthService $health_service = null ) {
        $this->health_service = $health_service ?? new ContaiQueueHealthService();
    }

    public function register_routes(): void {
        register_rest_route( $this->namespace, '/queue/snapshot', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_snapshot' ),
            'permission_callback' => array( $this, 'check_permissions' ),
        ) );

        register_rest_route( $this->namespace, '/queue/run', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'run_queue' ),
            'permission_callback' => array( $this, 'check_permissions' ),
            'show_in_index'       => false,
        ) );
    }

    /**
     * Restrict all queue routes to site administrators.
     */
    public function check_permissions(): bool {
        return current_user_can( 'manage_options' );
    }

    /**
     * GET /queue/snapshot
     * Returns the current queue health metrics for the admin banner.
     */
    public function get_snapshot( $request ) {
        return new \WP_REST_Response( $this->health_service->getSnapshot(), 200 );
    }

    /**
     * POST /queue/run
     * Triggers an immediate job-processor tick.
     *
     * Returns {"before": snapshot, "after": snapshot} so the caller can show
     * the operator what changed (e.g. pending count dropped, last_tick_at
     * advanced).
     */
    public function run_queue( $request ) {
        $user_id = get_current_user_id();

        if ( $this->isRateLimited( $user_id ) ) {
            return new \WP_REST_Response(
                array(
                    'code'    => 'rate_limited',
                    'message' => __( 'Espere unos segundos antes de volver a ejecutar la cola manualmente.', '1platform-content-ai' ),
                ),
                429
            );
        }

        $this->markRateLimit( $user_id );

        $before = $this->health_service->getSnapshot();

        if ( function_exists( 'contai_trigger_immediate_job_processing' ) ) {
            contai_trigger_immediate_job_processing();
        }

        $after = $this->health_service->getSnapshot();

        return new \WP_REST_Response(
            array(
                'before' => $before,
                'after'  => $after,
            ),
            200
        );
    }

    private function isRateLimited( int $user_id ): bool {
        if ( $user_id <= 0 ) {
            // Anonymous callers should already be blocked by permission_callback;
            // do not rate-limit them so legitimate test fixtures still work.
            return false;
        }

        $key = self::RATE_LIMIT_OPTION_PREFIX . $user_id;
        return (bool) get_transient( $key );
    }

    private function markRateLimit( int $user_id ): void {
        if ( $user_id <= 0 ) {
            return;
        }

        $key = self::RATE_LIMIT_OPTION_PREFIX . $user_id;
        set_transient( $key, 1, self::RATE_LIMIT_SECONDS );
    }
}
