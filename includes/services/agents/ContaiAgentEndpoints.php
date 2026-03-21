<?php
/**
 * Centralized agent API endpoint definitions.
 *
 * All agent-related endpoint paths consumed by this plugin are defined here.
 * Base URL (e.g. https://api.1platform.pro/api/v1) is configured in Config.php.
 * These paths are appended to the base URL by ContaiOnePlatformClient.
 *
 * OpenAPI source: 1Platform API v1 — Agents module
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ContaiAgentEndpoints {

	// ── Catalog ─────────────────────────────────────────────────
	const CATALOG          = '/agents/catalog';
	const CATALOG_TEMPLATE = '/agents/catalog/'; // append slug

	// ── Wizard ──────────────────────────────────────────────────
	const WIZARD_START   = '/agents/wizard/start';

	// ── Agents CRUD ─────────────────────────────────────────────
	const AGENTS = '/agents';

	// ── Agent Actions ───────────────────────────────────────────
	const AGENT_ACTIONS = '/agent-actions';

	// ── Parameterized Paths ─────────────────────────────────────

	public static function wizardRespond( $session_id ) {
		return '/agents/wizard/' . $session_id . '/respond';
	}

	public static function wizardConfirm( $session_id ) {
		return '/agents/wizard/' . $session_id . '/confirm';
	}

	public static function wizardGet( $session_id ) {
		return '/agents/wizard/' . $session_id;
	}

	public static function agentById( $id ) {
		return '/agents/' . $id;
	}

	public static function agentRun( $id ) {
		return '/agents/' . $id . '/run';
	}

	public static function agentRuns( $id ) {
		return '/agents/' . $id . '/runs';
	}

	public static function agentRunById( $id, $run_id ) {
		return '/agents/' . $id . '/runs/' . $run_id;
	}

	public static function agentRunStop( $id, $run_id ) {
		return '/agents/' . $id . '/runs/' . $run_id . '/stop';
	}

	public static function actionById( $action_id ) {
		return '/agent-actions/' . $action_id;
	}

	public static function actionConsume( $action_id ) {
		return '/agent-actions/' . $action_id . '/consume';
	}
}
