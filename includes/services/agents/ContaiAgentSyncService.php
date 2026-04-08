<?php
/**
 * Agent sync service — polls the 1Platform API for pending actions and
 * dispatches each one to the appropriate registered handler.
 *
 * This service is designed to be invoked by a WordPress cron event.
 * A hard minimum of 30 seconds between polls is enforced regardless of the
 * configured polling interval to prevent runaway requests.
 *
 * Handler registration:
 *   Default handlers (ContaiPublishContentActionHandler) are registered in
 *   registerDefaultHandlers().  Additional handlers can be registered at
 *   runtime via registerHandler().
 *
 * Action lifecycle:
 *   1. listActions( ['status' => 'pending'] ) — fetch pending actions from API.
 *   2. For each action, find a matching handler via canHandle().
 *   3. Call handler->handle(); on success, call consumeAction() to mark it done.
 *   4. Log the outcome via contai_log().
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/ContaiAgentApiService.php';
require_once __DIR__ . '/ContaiAgentSettingsService.php';
require_once __DIR__ . '/ContaiAgentActionHandler.php';

class ContaiAgentSyncService {

	/** Hard minimum seconds between poll cycles (rate-limit guard). */
	const MIN_POLL_INTERVAL_SECONDS = 30;

	private $api_service;

	/** @var ContaiAgentActionHandlerInterface[] */
	private $handlers = array();

	public function __construct( ContaiAgentApiService $api_service ) {
		$this->api_service = $api_service;
		$this->registerDefaultHandlers();
	}

	public static function create() {
		return new self( ContaiAgentApiService::create() );
	}

	// ── Handler registration ─────────────────────────────────────────────────

	/**
	 * Registers additional action handlers at runtime.
	 *
	 * Handlers are evaluated in registration order; the first handler whose
	 * canHandle() returns true wins.
	 *
	 * @param ContaiAgentActionHandlerInterface $handler
	 */
	public function registerHandler( ContaiAgentActionHandlerInterface $handler ) {
		$this->handlers[] = $handler;
	}

	// ── Polling ──────────────────────────────────────────────────────────────

	/**
	 * Fetches all pending agent actions from the API and processes them.
	 *
	 * Rate-limited: exits immediately if fewer than MIN_POLL_INTERVAL_SECONDS
	 * have elapsed since the last poll, regardless of cron schedule.
	 */
	public function pollAndProcessActions() {
		$last_poll = ContaiAgentSettingsService::getLastPollTime();

		$interval = max( self::MIN_POLL_INTERVAL_SECONDS, ContaiAgentSettingsService::getPollingInterval() );
		if ( ( time() - $last_poll ) < $interval ) {
			return;
		}

		// Acquire exclusive lock to prevent concurrent polling.
		global $wpdb;
		$lock_name = 'contai_agent_poll_lock';
		$got_lock  = $wpdb->get_var( $wpdb->prepare( "SELECT GET_LOCK(%s, 0)", $lock_name ) );
		if ( '1' !== (string) $got_lock ) {
			return; // Another process is already polling.
		}

		// Mark last poll time BEFORE the API call to prevent TOCTOU race.
		ContaiAgentSettingsService::setLastPollTime( time() );

		try {
			$result = $this->api_service->listActions( array( 'status' => 'pending' ) );

			if ( is_wp_error( $result ) || ! is_array( $result ) || empty( $result['actions'] ) || ! is_array( $result['actions'] ) ) {
				return;
			}

			// Cron-triggered runs use the configured publish_status via settings.
			$settings                    = ContaiAgentSettingsService::getAllSettings();
			$settings['is_auto_consume'] = true;

			foreach ( $result['actions'] as $action ) {
				if ( ! is_array( $action ) ) {
					continue;
				}
				// Skip actions that are not pending (prevent double processing).
				if ( 'pending' !== ( $action['status'] ?? '' ) ) {
					continue;
				}
				$this->processAction( $action, $settings );
			}
		} finally {
			// Always release lock, even if processAction() throws.
			$wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );
		}
	}

	// ── Manual consume ───────────────────────────────────────────────────────

	/**
	 * Fetches a single action and, if it is still pending, processes it
	 * immediately as a manual (non-auto) consume.  Returns the refreshed
	 * action state from the API after processing, or a WP_Error on failure.
	 *
	 * @param string $action_id MongoDB ObjectId of the action.
	 * @return array|\WP_Error
	 */
	public function consumeActionManually( $action_id ) {
		$action = $this->api_service->getAction( $action_id );
		if ( is_wp_error( $action ) || null === $action ) {
			return $action;
		}

		$status = $action['status'] ?? '';

		if ( 'pending' !== $status ) {
			return $action; // Already consumed, return current state.
		}

		$settings                    = ContaiAgentSettingsService::getAllSettings();
		$settings['is_auto_consume'] = false; // Manual = respects publish preference.

		$this->processAction( $action, $settings );

		// Return fresh state.
		return $this->api_service->getAction( $action_id );
	}

	// ── Dismiss ─────────────────────────────────────────────────────────────

	/**
	 * Dismisses a single pending action by consuming it on the API with a
	 * "dismissed" marker, without creating any WordPress content.
	 *
	 * @param string $action_id MongoDB ObjectId of the action.
	 * @return array|\WP_Error Refreshed action state or WP_Error on failure.
	 */
	public function dismissAction( $action_id ) {
		$action = $this->api_service->getAction( $action_id );
		if ( is_wp_error( $action ) || null === $action ) {
			return $action;
		}

		if ( 'pending' !== ( $action['status'] ?? '' ) ) {
			return $action;
		}

		$consume_payload = array(
			'result'      => array( 'dismissed' => true ),
			'consumed_by' => 'wordpress-plugin-dismissed',
		);
		$result = $this->api_service->consumeAction( $action_id, $consume_payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		contai_log( 'Agent action dismissed: ' . $action_id );

		return $this->api_service->getAction( $action_id );
	}

	/**
	 * Dismisses all currently pending actions in bulk.
	 *
	 * @return array{dismissed: int, errors: int}
	 */
	public function dismissAllPendingActions() {
		$result = $this->api_service->listActions( array( 'status' => 'pending', 'limit' => 100 ) );

		$actions = array();
		if ( ! is_wp_error( $result ) && ! empty( $result['actions'] ) && is_array( $result['actions'] ) ) {
			$actions = $result['actions'];
		}

		$dismissed = 0;
		$errors    = 0;

		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) || 'pending' !== ( $action['status'] ?? '' ) ) {
				continue;
			}
			$action_id       = isset( $action['id'] ) ? $action['id'] : ( isset( $action['_id'] ) ? $action['_id'] : '' );
			$consume_payload = array(
				'result'      => array( 'dismissed' => true ),
				'consumed_by' => 'wordpress-plugin-dismissed',
			);
			$res = $this->api_service->consumeAction( $action_id, $consume_payload );
			if ( is_wp_error( $res ) ) {
				$errors++;
			} else {
				$dismissed++;
			}
		}

		contai_log( "Bulk dismiss: {$dismissed} dismissed, {$errors} errors" );

		return array( 'dismissed' => $dismissed, 'errors' => $errors );
	}

	// ── Action dispatch ──────────────────────────────────────────────────────

	/**
	 * Dispatches a single action to its registered handler.
	 *
	 * If the handler succeeds the action is consumed remotely via the API.
	 * Failures are logged but do not throw — the poll cycle continues with
	 * the remaining actions.
	 *
	 * @param array $action   Full action object from the API.
	 * @param array $settings Settings context (from getAllSettings() + overrides).
	 */
	public function processAction( array $action, array $settings ) {
		$type      = isset( $action['type'] ) ? $action['type'] : '';
		$action_id = isset( $action['id'] ) ? $action['id'] : '';

		foreach ( $this->handlers as $handler ) {
			if ( ! $handler->canHandle( $type ) ) {
				continue;
			}

			$result = $handler->handle( $action, $settings );

			if ( ! empty( $result['success'] ) ) {
				$consume_payload = array(
					'result'      => $result,
					'consumed_by' => 'wordpress-plugin',
				);
				$this->api_service->consumeAction( $action_id, $consume_payload );
				contai_log( 'Agent action consumed: ' . $action_id . ' type=' . $type );
			} else {
				$error = isset( $result['error'] ) ? $result['error'] : 'unknown';
				contai_log( 'Agent action failed: ' . $action_id . ' error=' . $error, 'warning' );
			}

			return;
		}

		contai_log( 'No handler for agent action type: ' . $type, 'warning' );
	}

	// ── Private ──────────────────────────────────────────────────────────────

	/**
	 * Registers the built-in action handlers.
	 * Called once during construction before any external handlers are added.
	 */
	private function registerDefaultHandlers() {
		require_once dirname( __DIR__ ) . '/post/WordPressPostCreator.php';
		$this->handlers[] = new ContaiPublishContentActionHandler( new ContaiWordPressPostCreator() );

		require_once __DIR__ . '/ContaiSendEmailActionHandler.php';
		$this->handlers[] = new ContaiSendEmailActionHandler();
	}
}
