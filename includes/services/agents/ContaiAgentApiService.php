<?php
/**
 * Agent API service — communicates with the 1Platform Agent API.
 *
 * Wraps ContaiOnePlatformClient to provide typed, named methods for every
 * agent-related endpoint.  All responses are normalised through handleResponse()
 * which returns the 'data' payload on success or null on failure (with logging).
 *
 * The catalog endpoint is cached for one hour via WordPress transients to avoid
 * redundant round-trips on pages that render the agent selection UI.
 *
 * cURL examples (import to Postman):
 *
 * # List catalog
 * curl -X GET https://api-qa.1platform.pro/api/v1/agents/catalog \
 *   -H "Authorization: Bearer <APP_TOKEN>" \
 *   -H "x-user-token: <USER_TOKEN>"
 *
 * # Start wizard
 * curl -X POST https://api-qa.1platform.pro/api/v1/agents/wizard/start \
 *   -H "Content-Type: application/json" \
 *   -H "Authorization: Bearer <APP_TOKEN>" \
 *   -H "x-user-token: <USER_TOKEN>" \
 *   -d '{"template_slug":"blog-writer"}'
 *
 * # List pending actions
 * curl -X GET "https://api-qa.1platform.pro/api/v1/agent-actions?status=pending" \
 *   -H "Authorization: Bearer <APP_TOKEN>" \
 *   -H "x-user-token: <USER_TOKEN>"
 *
 * # Consume action
 * curl -X PATCH https://api-qa.1platform.pro/api/v1/agent-actions/<ID>/consume \
 *   -H "Content-Type: application/json" \
 *   -H "Authorization: Bearer <APP_TOKEN>" \
 *   -H "x-user-token: <USER_TOKEN>" \
 *   -d '{"result":{},"consumed_by":"wordpress-plugin"}'
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/ContaiAgentEndpoints.php';

class ContaiAgentApiService {

	const CATALOG_TRANSIENT_KEY = 'contai_agents_catalog';

	private ContaiOnePlatformClient $client;

	public function __construct( ContaiOnePlatformClient $client ) {
		$this->client = $client;
	}

	public static function create() {
		return new self( ContaiOnePlatformClient::create() );
	}

	// ── Catalog ─────────────────────────────────────────────────

	/**
	 * Returns the full agent template catalog.
	 * Result is cached in a transient for one hour to reduce API load.
	 *
	 * @return array|null Catalog data on success, null on failure.
	 */
	public function getCatalog() {
		$cached = get_transient( self::CATALOG_TRANSIENT_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = $this->client->get( ContaiAgentEndpoints::CATALOG );
		$result   = $this->handleResponse( $response, 'getCatalog' );

		if ( ! is_wp_error( $result ) && ! empty( $result ) ) {
			set_transient( self::CATALOG_TRANSIENT_KEY, $result, HOUR_IN_SECONDS );
		} else {
			delete_transient( self::CATALOG_TRANSIENT_KEY );
		}

		return $result;
	}

	/**
	 * Returns a single catalog template by slug.
	 *
	 * @param string $slug Template slug.
	 * @return array|null
	 */
	public function getCatalogTemplate( $slug ) {
		$endpoint = ContaiAgentEndpoints::CATALOG_TEMPLATE . rawurlencode( $slug );
		$response = $this->client->get( $endpoint );
		return $this->handleResponse( $response, 'getCatalogTemplate' );
	}

	// ── Wizard ──────────────────────────────────────────────────

	/**
	 * Starts a new wizard session for the given template.
	 *
	 * @param array $payload e.g. ['template_slug' => 'blog-writer']
	 * @return array|null Session data (including session_id) on success.
	 */
	public function startWizard( array $payload ) {
		$response = $this->client->post( ContaiAgentEndpoints::WIZARD_START, $payload );
		return $this->handleResponse( $response, 'startWizard' );
	}

	/**
	 * Submits a user response to an active wizard session.
	 *
	 * @param string $session_id
	 * @param array  $payload    e.g. ['answer' => '...']
	 * @return array|null
	 */
	public function respondWizard( $session_id, array $payload ) {
		$endpoint = ContaiAgentEndpoints::wizardRespond( $session_id );
		$response = $this->client->post( $endpoint, $payload );
		return $this->handleResponse( $response, 'respondWizard' );
	}

	/**
	 * Confirms and finalises a wizard session, creating the agent.
	 *
	 * @param string $session_id
	 * @return array|null Created agent data on success.
	 */
	public function confirmWizard( $session_id ) {
		$endpoint = ContaiAgentEndpoints::wizardConfirm( $session_id );
		$response = $this->client->post( $endpoint, array(
			'site_url' => get_site_url(),
		) );
		return $this->handleResponse( $response, 'confirmWizard' );
	}

	/**
	 * Fetches the current state of a wizard session.
	 *
	 * @param string $session_id
	 * @return array|null
	 */
	public function getWizardSession( $session_id ) {
		$endpoint = ContaiAgentEndpoints::wizardGet( $session_id );
		$response = $this->client->get( $endpoint );
		return $this->handleResponse( $response, 'getWizardSession' );
	}

	// ── Agent CRUD ───────────────────────────────────────────────

	/**
	 * Creates a new agent.
	 *
	 * @param array $payload Agent definition.
	 * @return array|null
	 */
	public function createAgent( array $payload ) {
		$response = $this->client->post( ContaiAgentEndpoints::AGENTS, $payload );
		return $this->handleResponse( $response, 'createAgent' );
	}

	/**
	 * Lists agents for the authenticated user.
	 *
	 * @param array $params Optional query params (e.g. ['page' => 1, 'limit' => 20]).
	 * @return array|null
	 */
	public function listAgents( array $params = array() ) {
		$response = $this->client->get( ContaiAgentEndpoints::AGENTS, $params );
		return $this->handleResponse( $response, 'listAgents' );
	}

	/**
	 * Fetches a single agent by ID.
	 *
	 * @param string $id Agent ID.
	 * @return array|null
	 */
	public function getAgent( $id ) {
		$endpoint = ContaiAgentEndpoints::agentById( $id );
		$response = $this->client->get( $endpoint );
		return $this->handleResponse( $response, 'getAgent' );
	}

	/**
	 * Replaces an agent's configuration.
	 *
	 * @param string $id      Agent ID.
	 * @param array  $payload Updated agent definition.
	 * @return array|null
	 */
	public function updateAgent( $id, array $payload ) {
		$endpoint = ContaiAgentEndpoints::agentById( $id );
		$response = $this->client->put( $endpoint, $payload );
		return $this->handleResponse( $response, 'updateAgent' );
	}

	/**
	 * Deletes an agent.
	 *
	 * @param string $id Agent ID.
	 * @return array|null
	 */
	public function deleteAgent( $id ) {
		$endpoint = ContaiAgentEndpoints::agentById( $id );
		$response = $this->client->delete( $endpoint );
		return $this->handleResponse( $response, 'deleteAgent' );
	}

	// ── Runs ────────────────────────────────────────────────────

	/**
	 * Triggers a new run for the given agent.
	 *
	 * @param string $id      Agent ID.
	 * @param array  $payload Optional run parameters.
	 * @return array|null
	 */
	public function runAgent( $id, array $payload = array() ) {
		$endpoint = ContaiAgentEndpoints::agentRun( $id );
		$response = $this->client->post( $endpoint, $payload );
		return $this->handleResponse( $response, 'runAgent' );
	}

	/**
	 * Lists all runs for an agent.
	 *
	 * @param string $id     Agent ID.
	 * @param array  $params Optional query params.
	 * @return array|null
	 */
	public function listRuns( $id, array $params = array() ) {
		$endpoint = ContaiAgentEndpoints::agentRuns( $id );
		$response = $this->client->get( $endpoint, $params );
		return $this->handleResponse( $response, 'listRuns' );
	}

	/**
	 * Fetches a single run by ID.
	 *
	 * @param string $id     Agent ID.
	 * @param string $run_id Run ID.
	 * @return array|null
	 */
	public function getRun( $id, $run_id ) {
		$endpoint = ContaiAgentEndpoints::agentRunById( $id, $run_id );
		$response = $this->client->get( $endpoint );
		return $this->handleResponse( $response, 'getRun' );
	}

	/**
	 * Stops a running or pending agent run.
	 *
	 * @param string $id     Agent ID.
	 * @param string $run_id Run ID.
	 * @return array|null
	 */
	public function stopRun( $id, $run_id ) {
		$endpoint = ContaiAgentEndpoints::agentRunStop( $id, $run_id );
		$response = $this->client->post( $endpoint, array() );
		return $this->handleResponse( $response, 'stopRun' );
	}

	// ── Actions ──────────────────────────────────────────────────

	/**
	 * Lists agent actions, optionally filtered by status.
	 *
	 * @param array $params e.g. ['status' => 'pending']
	 * @return array|null
	 */
	public function listActions( array $params = array() ) {
		$response = $this->client->get( ContaiAgentEndpoints::AGENT_ACTIONS, $params );
		return $this->handleResponse( $response, 'listActions' );
	}

	/**
	 * Fetches a single action by ID.
	 *
	 * @param string $action_id
	 * @return array|null
	 */
	public function getAction( $action_id ) {
		$endpoint = ContaiAgentEndpoints::actionById( $action_id );
		$response = $this->client->get( $endpoint );
		return $this->handleResponse( $response, 'getAction' );
	}

	/**
	 * Marks an action as consumed and records the processing result.
	 *
	 * @param string $action_id
	 * @param array  $payload   e.g. ['result' => [...], 'consumed_by' => 'wordpress-plugin']
	 * @return array|null
	 */
	public function consumeAction( $action_id, array $payload = array() ) {
		$endpoint = ContaiAgentEndpoints::actionConsume( $action_id );
		$response = $this->client->patch( $endpoint, $payload );
		return $this->handleResponse( $response, 'consumeAction' );
	}

	// ── Private Helpers ──────────────────────────────────────────

	/**
	 * Normalises a ContaiOnePlatformResponse into either a data array or a WP_Error.
	 *
	 * Returns a WP_Error on transport failure or a non-success HTTP status so
	 * callers can propagate structured error information to REST responses.
	 *
	 * @param ContaiOnePlatformResponse|null $response
	 * @param string                         $context  Method name for log messages.
	 * @return array|\WP_Error Data payload on success, WP_Error on failure.
	 */
	private function handleResponse( $response, $context ) {
		if ( null === $response ) {
			contai_log( "AgentApiService::{$context} — null response (transport error)", 'warning' );
			return new \WP_Error( 'transport_error', 'Could not connect to 1Platform API', array( 'status' => 502 ) );
		}

		if ( $response->isSuccess() ) {
			return $response->getData();
		}

		$status  = $response->getStatusCode();
		$message = $response->getMessage() ?: 'Unknown error from 1Platform API';
		contai_log( "AgentApiService::{$context} — HTTP {$status}: {$message}", 'warning' );

		return new \WP_Error( 'api_error', $message, array( 'status' => $status ?: 500 ) );
	}
}
