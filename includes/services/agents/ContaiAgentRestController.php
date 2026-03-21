<?php
/**
 * REST API controller for the Agents module.
 *
 * Proxies requests from the WP admin UI to the 1Platform API via
 * ContaiAgentApiService. All routes are registered under the 'contai/v1'
 * namespace and are restricted to users with 'manage_options' capability.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/ContaiAgentApiService.php';
require_once __DIR__ . '/ContaiAgentSettingsService.php';
require_once __DIR__ . '/ContaiAgentSyncService.php';

class ContaiAgentRestController {

    private $namespace = 'contai/v1';

    /** @var ContaiAgentApiService */
    private $api_service;

    public function __construct() {
        $this->api_service = ContaiAgentApiService::create();
    }

    // ── Route Registration ───────────────────────────────────────────────────

    public function register_routes() {

        // Catalog
        register_rest_route( $this->namespace, '/agents/catalog', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_catalog' ),
            'permission_callback' => array( $this, 'check_permissions' ),
        ) );

        register_rest_route( $this->namespace, '/agents/catalog/(?P<slug>[a-z0-9-]+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_catalog_template' ),
            'permission_callback' => array( $this, 'check_permissions' ),
            'args'                => array(
                'slug' => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_key',
                ),
            ),
        ) );

        // Wizard
        register_rest_route( $this->namespace, '/agents/wizard/start', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'start_wizard' ),
            'permission_callback' => array( $this, 'check_permissions' ),
            'show_in_index'       => false,
        ) );

        register_rest_route( $this->namespace, '/agents/wizard/(?P<session_id>[a-f0-9]{24})/respond', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'respond_wizard' ),
            'permission_callback' => array( $this, 'check_permissions' ),
            'show_in_index'       => false,
            'args'                => $this->get_mongo_id_args( 'session_id' ),
        ) );

        register_rest_route( $this->namespace, '/agents/wizard/(?P<session_id>[a-f0-9]{24})/confirm', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'confirm_wizard' ),
            'permission_callback' => array( $this, 'check_permissions' ),
            'show_in_index'       => false,
            'args'                => $this->get_mongo_id_args( 'session_id' ),
        ) );

        register_rest_route( $this->namespace, '/agents/wizard/(?P<session_id>[a-f0-9]{24})', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_wizard_session' ),
            'permission_callback' => array( $this, 'check_permissions' ),
            'show_in_index'       => false,
            'args'                => $this->get_mongo_id_args( 'session_id' ),
        ) );

        // CRUD
        register_rest_route( $this->namespace, '/agents', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'list_agents' ),
                'permission_callback' => array( $this, 'check_permissions' ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'create_agent' ),
                'permission_callback' => array( $this, 'check_permissions' ),
            ),
        ) );

        register_rest_route( $this->namespace, '/agents/(?P<id>[a-f0-9]{24})', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_agent' ),
                'permission_callback' => array( $this, 'check_permissions' ),
                'args'                => $this->get_mongo_id_args( 'id' ),
            ),
            array(
                'methods'             => 'PUT',
                'callback'            => array( $this, 'update_agent' ),
                'permission_callback' => array( $this, 'check_permissions' ),
                'args'                => $this->get_mongo_id_args( 'id' ),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array( $this, 'delete_agent' ),
                'permission_callback' => array( $this, 'check_permissions' ),
                'args'                => $this->get_mongo_id_args( 'id' ),
            ),
        ) );

        // Execution
        register_rest_route( $this->namespace, '/agents/(?P<id>[a-f0-9]{24})/run', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'run_agent' ),
            'permission_callback' => array( $this, 'check_permissions' ),
            'show_in_index'       => false,
            'args'                => $this->get_mongo_id_args( 'id' ),
        ) );

        register_rest_route( $this->namespace, '/agents/(?P<id>[a-f0-9]{24})/runs', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'list_runs' ),
            'permission_callback' => array( $this, 'check_permissions' ),
            'args'                => $this->get_mongo_id_args( 'id' ),
        ) );

        register_rest_route( $this->namespace, '/agents/(?P<id>[a-f0-9]{24})/runs/(?P<run_id>[a-f0-9]{24})', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_run' ),
            'permission_callback' => array( $this, 'check_permissions' ),
            'args'                => array_merge( $this->get_mongo_id_args( 'id' ), $this->get_mongo_id_args( 'run_id' ) ),
        ) );

        register_rest_route( $this->namespace, '/agents/(?P<id>[a-f0-9]{24})/runs/(?P<run_id>[a-f0-9]{24})/stop', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'stop_run' ),
            'permission_callback' => array( $this, 'check_permissions' ),
            'show_in_index'       => false,
            'args'                => array_merge( $this->get_mongo_id_args( 'id' ), $this->get_mongo_id_args( 'run_id' ) ),
        ) );

        // Actions
        register_rest_route( $this->namespace, '/agent-actions', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'list_actions' ),
            'permission_callback' => array( $this, 'check_permissions' ),
        ) );

        register_rest_route( $this->namespace, '/agent-actions/(?P<action_id>[a-f0-9]{24})', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_action' ),
            'permission_callback' => array( $this, 'check_permissions' ),
            'args'                => $this->get_mongo_id_args( 'action_id' ),
        ) );

        register_rest_route( $this->namespace, '/agent-actions/(?P<action_id>[a-f0-9]{24})/consume', array(
            'methods'             => 'PATCH',
            'callback'            => array( $this, 'consume_action' ),
            'permission_callback' => array( $this, 'check_permissions' ),
            'show_in_index'       => false,
            'args'                => $this->get_mongo_id_args( 'action_id' ),
        ) );

        register_rest_route( $this->namespace, '/agent-actions/(?P<action_id>[a-f0-9]{24})/dismiss', array(
            'methods'             => 'PATCH',
            'callback'            => array( $this, 'dismiss_action' ),
            'permission_callback' => array( $this, 'check_permissions' ),
            'show_in_index'       => false,
            'args'                => $this->get_mongo_id_args( 'action_id' ),
        ) );

        register_rest_route( $this->namespace, '/agent-actions/dismiss-all', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'dismiss_all_actions' ),
            'permission_callback' => array( $this, 'check_permissions' ),
            'show_in_index'       => false,
        ) );

        // Settings
        register_rest_route( $this->namespace, '/agents/settings', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_settings' ),
                'permission_callback' => array( $this, 'check_permissions' ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'update_settings' ),
                'permission_callback' => array( $this, 'check_permissions' ),
            ),
        ) );
    }

    // ── Permission Callback ──────────────────────────────────────────────────

    /**
     * Restricts all agent routes to site administrators.
     *
     * @return bool
     */
    public function check_permissions() {
        return current_user_can( 'manage_options' );
    }

    // ── Shared Helpers ───────────────────────────────────────────────────────

    /**
     * Returns the route args definition that validates a MongoDB ObjectId
     * URL parameter by name.
     *
     * @param string $name Parameter name (e.g. 'id', 'session_id', 'run_id').
     * @return array
     */
    private function get_mongo_id_args( $name ) {
        return array(
            $name => array(
                'required'          => true,
                'validate_callback' => function( $value ) {
                    return (bool) preg_match( '/^[a-f0-9]{24}$/', $value );
                },
                'sanitize_callback' => 'sanitize_text_field',
            ),
        );
    }

    /**
     * Builds a WP_Error from an API service response that returned null or
     * from an error message string.
     *
     * @param string $code    WP error code.
     * @param string $message Human-readable error message.
     * @param int    $status  HTTP status code to send with the error.
     * @return WP_Error
     */
    private function error( $code, $message, $status = 500 ) {
        return new WP_Error( $code, $message, array( 'status' => $status ) );
    }

    /**
     * Converts a WP_Error API result into a WP_REST_Response with the correct
     * HTTP status.  Returns null when the result is NOT a WP_Error so callers
     * can short-circuit cleanly.
     *
     * Usage:
     *   if ( $err = $this->error_from_api( $data ) ) return $err;
     *
     * @param mixed $result Value returned by the API service method.
     * @return WP_REST_Response|null
     */
    private function error_from_api( $result ) {
        if ( is_wp_error( $result ) ) {
            $status = $result->get_error_data()['status'] ?? 500;
            return new \WP_REST_Response(
                array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ),
                $status
            );
        }
        return null; // Not an error
    }

    // ── Catalog Callbacks ────────────────────────────────────────────────────

    /**
     * GET /agents/catalog
     * Returns the full agent template catalog from the 1Platform API.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_catalog( $request ) {
        $data = $this->api_service->getCatalog();

        if ( $err = $this->error_from_api( $data ) ) {
            return $err;
        }

        return new WP_REST_Response( $data, 200 );
    }

    /**
     * GET /agents/catalog/{slug}
     * Returns a single agent template from the catalog by its slug.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_catalog_template( $request ) {
        $slug = sanitize_key( $request->get_param( 'slug' ) );

        $data = $this->api_service->getCatalogTemplate( $slug );

        if ( $err = $this->error_from_api( $data ) ) {
            return $err;
        }

        return new WP_REST_Response( $data, 200 );
    }

    // ── Wizard Callbacks ─────────────────────────────────────────────────────

    /**
     * POST /agents/wizard/start
     * Initiates a new wizard session for guided agent configuration.
     *
     * Expected JSON body forwarded as-is to the API (e.g. { "template_slug": "..." }).
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function start_wizard( $request ) {
        $body = $request->get_json_params();

        if ( empty( $body ) ) {
            $body = array();
        }

        $data = $this->api_service->startWizard( $body );

        if ( $err = $this->error_from_api( $data ) ) {
            return $err;
        }

        return new WP_REST_Response( $data, 201 );
    }

    /**
     * POST /agents/wizard/{session_id}/respond
     * Submits a user response to the current wizard step.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function respond_wizard( $request ) {
        $session_id = sanitize_text_field( $request->get_param( 'session_id' ) );
        $body       = $request->get_json_params();

        if ( empty( $body ) ) {
            $body = array();
        }

        $data = $this->api_service->respondWizard( $session_id, $body );

        if ( $err = $this->error_from_api( $data ) ) {
            return $err;
        }

        return new WP_REST_Response( $data, 200 );
    }

    /**
     * POST /agents/wizard/{session_id}/confirm
     * Confirms the completed wizard session and creates the agent.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function confirm_wizard( $request ) {
        $session_id = sanitize_text_field( $request->get_param( 'session_id' ) );
        $data       = $this->api_service->confirmWizard( $session_id );

        if ( $err = $this->error_from_api( $data ) ) {
            return $err;
        }

        return new WP_REST_Response( $data, 201 );
    }

    /**
     * GET /agents/wizard/{session_id}
     * Retrieves the current state of an in-progress wizard session.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_wizard_session( $request ) {
        $session_id = sanitize_text_field( $request->get_param( 'session_id' ) );

        $data = $this->api_service->getWizardSession( $session_id );

        if ( $err = $this->error_from_api( $data ) ) {
            return $err;
        }

        return new WP_REST_Response( $data, 200 );
    }

    // ── CRUD Callbacks ───────────────────────────────────────────────────────

    /**
     * GET /agents
     * Returns a paginated list of agents for the authenticated account.
     *
     * Supported query params: page, limit, status (forwarded to API).
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function list_agents( $request ) {
        $query = array();

        $page = $request->get_param( 'page' );
        if ( null !== $page ) {
            $query['page'] = absint( $page );
        }

        $limit = $request->get_param( 'limit' );
        if ( null !== $limit ) {
            $query['limit'] = absint( $limit );
        }

        $status = $request->get_param( 'status' );
        if ( null !== $status ) {
            $query['status'] = sanitize_text_field( $status );
        }

        $data = $this->api_service->listAgents( $query );

        if ( $err = $this->error_from_api( $data ) ) {
            return $err;
        }

        return new WP_REST_Response( $data, 200 );
    }

    /**
     * POST /agents
     * Creates a new agent using the provided configuration body.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function create_agent( $request ) {
        $body = $request->get_json_params();

        if ( empty( $body ) ) {
            return $this->error( 'missing_body', 'Request body is required.', 400 );
        }

        $data = $this->api_service->createAgent( $body );

        if ( $err = $this->error_from_api( $data ) ) {
            return $err;
        }

        return new WP_REST_Response( $data, 201 );
    }

    /**
     * GET /agents/{id}
     * Returns a single agent by its MongoDB ObjectId.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_agent( $request ) {
        $id = sanitize_text_field( $request->get_param( 'id' ) );

        $data = $this->api_service->getAgent( $id );

        if ( $err = $this->error_from_api( $data ) ) {
            return $err;
        }

        return new WP_REST_Response( $data, 200 );
    }

    /**
     * PUT /agents/{id}
     * Replaces an agent's configuration with the provided body.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function update_agent( $request ) {
        $id   = sanitize_text_field( $request->get_param( 'id' ) );
        $body = $request->get_json_params();

        if ( empty( $body ) ) {
            return $this->error( 'missing_body', 'Request body is required.', 400 );
        }

        $data = $this->api_service->updateAgent( $id, $body );

        if ( $err = $this->error_from_api( $data ) ) {
            return $err;
        }

        return new WP_REST_Response( $data, 200 );
    }

    /**
     * DELETE /agents/{id}
     * Deletes an agent permanently.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function delete_agent( $request ) {
        $id = sanitize_text_field( $request->get_param( 'id' ) );

        $data = $this->api_service->deleteAgent( $id );

        if ( $err = $this->error_from_api( $data ) ) {
            return $err;
        }

        return new WP_REST_Response( $data, 200 );
    }

    // ── Execution Callbacks ──────────────────────────────────────────────────

    /**
     * POST /agents/{id}/run
     * Triggers an on-demand execution run for the given agent.
     *
     * An optional JSON body may carry runtime overrides forwarded to the API.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function run_agent( $request ) {
        $id   = sanitize_text_field( $request->get_param( 'id' ) );
        $body = $request->get_json_params();

        if ( empty( $body ) || ! is_array( $body ) ) {
            $body = array();
        }

        $data = $this->api_service->runAgent( $id, $body );

        if ( $err = $this->error_from_api( $data ) ) {
            return $err;
        }

        return new WP_REST_Response( $data, 202 );
    }

    /**
     * GET /agents/{id}/runs
     * Returns the execution history for the given agent.
     *
     * Supported query params: page, limit (forwarded to API).
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function list_runs( $request ) {
        $id    = sanitize_text_field( $request->get_param( 'id' ) );
        $query = array();

        $page = $request->get_param( 'page' );
        if ( null !== $page ) {
            $query['page'] = absint( $page );
        }

        $limit = $request->get_param( 'limit' );
        if ( null !== $limit ) {
            $query['limit'] = absint( $limit );
        }

        $data = $this->api_service->listRuns( $id, $query );

        if ( $err = $this->error_from_api( $data ) ) {
            return $err;
        }

        return new WP_REST_Response( $data, 200 );
    }

    /**
     * GET /agents/{id}/runs/{run_id}
     * Returns the details of a single execution run.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_run( $request ) {
        $id     = sanitize_text_field( $request->get_param( 'id' ) );
        $run_id = sanitize_text_field( $request->get_param( 'run_id' ) );

        $data = $this->api_service->getRun( $id, $run_id );

        if ( $err = $this->error_from_api( $data ) ) {
            return $err;
        }

        return new WP_REST_Response( $data, 200 );
    }

    /**
     * POST /agents/{id}/runs/{run_id}/stop
     * Stops a running or pending agent run.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function stop_run( $request ) {
        $id     = sanitize_text_field( $request->get_param( 'id' ) );
        $run_id = sanitize_text_field( $request->get_param( 'run_id' ) );

        $data = $this->api_service->stopRun( $id, $run_id );

        if ( $err = $this->error_from_api( $data ) ) {
            return $err;
        }

        return new WP_REST_Response( $data, 200 );
    }

    // ── Action Callbacks ─────────────────────────────────────────────────────

    /**
     * GET /agent-actions
     * Returns a list of pending or recent agent actions for this site.
     *
     * Supported query params: status, page, limit (forwarded to API).
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function list_actions( $request ) {
        $query = array();

        $status = $request->get_param( 'status' );
        if ( null !== $status ) {
            $query['status'] = sanitize_text_field( $status );
        }

        $page = $request->get_param( 'page' );
        if ( null !== $page ) {
            $query['page'] = absint( $page );
        }

        $limit = $request->get_param( 'limit' );
        if ( null !== $limit ) {
            $query['limit'] = absint( $limit );
        }

        $data = $this->api_service->listActions( $query );

        if ( $err = $this->error_from_api( $data ) ) {
            return $err;
        }

        return new WP_REST_Response( $data, 200 );
    }

    /**
     * GET /agent-actions/{action_id}
     * Returns a single action by its MongoDB ObjectId.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_action( $request ) {
        $action_id = sanitize_text_field( $request->get_param( 'action_id' ) );

        $data = $this->api_service->getAction( $action_id );

        if ( $err = $this->error_from_api( $data ) ) {
            return $err;
        }

        return new WP_REST_Response( $data, 200 );
    }

    /**
     * PATCH /agent-actions/{action_id}/consume
     * Manually consumes a pending action.
     *
     * Orchestration is delegated to ContaiAgentSyncService::consumeActionManually()
     * which fetches the action, processes it if pending, and returns the
     * refreshed action state.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function consume_action( $request ) {
        $action_id = sanitize_text_field( $request->get_param( 'action_id' ) );
        $sync      = ContaiAgentSyncService::create();
        $result    = $sync->consumeActionManually( $action_id );

        if ( $err = $this->error_from_api( $result ) ) {
            return $err;
        }
        if ( null === $result ) {
            return $this->error( 'not_found', 'Action not found', 404 );
        }
        return new WP_REST_Response( $result, 200 );
    }

    /**
     * PATCH /agent-actions/{action_id}/dismiss
     * Dismisses a pending action without creating content.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function dismiss_action( $request ) {
        $action_id = sanitize_text_field( $request->get_param( 'action_id' ) );
        $sync      = ContaiAgentSyncService::create();
        $result    = $sync->dismissAction( $action_id );

        if ( $err = $this->error_from_api( $result ) ) {
            return $err;
        }
        if ( null === $result ) {
            return $this->error( 'not_found', 'Action not found', 404 );
        }
        return new WP_REST_Response( $result, 200 );
    }

    /**
     * POST /agent-actions/dismiss-all
     * Dismisses all pending actions in bulk.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function dismiss_all_actions( $request ) {
        $sync   = ContaiAgentSyncService::create();
        $result = $sync->dismissAllPendingActions();
        return new WP_REST_Response( $result, 200 );
    }

    // ── Settings Callbacks ───────────────────────────────────────────────────

    /**
     * GET /agents/settings
     * Returns the locally stored agent preferences (wp_options).
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_settings( $request ) {
        $settings = ContaiAgentSettingsService::getAllSettings();
        return new WP_REST_Response( $settings, 200 );
    }

    /**
     * POST /agents/settings
     * Persists one or more agent preference values to wp_options.
     *
     * Accepted keys: publish_status, auto_consume, polling_interval.
     * Unknown keys are silently ignored by ContaiAgentSettingsService.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function update_settings( $request ) {
        $body = $request->get_json_params();

        if ( empty( $body ) || ! is_array( $body ) ) {
            return $this->error( 'missing_body', 'Request body must be a JSON object.', 400 );
        }

        // Sanitize recognised scalar fields before passing to the service.
        $clean = array();

        if ( isset( $body['publish_status'] ) ) {
            $clean['publish_status'] = sanitize_text_field( $body['publish_status'] );
        }

        if ( isset( $body['auto_consume'] ) ) {
            $clean['auto_consume'] = (bool) $body['auto_consume'];
        }

        if ( isset( $body['polling_interval'] ) ) {
            $clean['polling_interval'] = absint( $body['polling_interval'] );
        }

        ContaiAgentSettingsService::updateSettings( $clean );

        return new WP_REST_Response( ContaiAgentSettingsService::getAllSettings(), 200 );
    }
}
