<?php
/**
 * Durable, debug-independent warning record for site generation (#48).
 *
 * @package 1Platform_Content_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option holding a bounded FIFO of site-generation warnings.
 *
 * Read it off a generated site with:
 *   wp option get contai_site_generation_warnings
 */
const CONTAI_SITE_WARNINGS_OPTION = 'contai_site_generation_warnings';

/** Keep the buffer bounded, like ContaiClientLogReporter does. */
const CONTAI_SITE_WARNINGS_MAX = 20;

/**
 * Record a site-generation warning where it can actually be found.
 *
 * v2.38.12 introduced this store for failing optional steps, because
 * contai_log() writes nothing unless WP_DEBUG is on and production runs with
 * it off. The nav-menu location resolvers need the same treatment for the same
 * reason: every root cause on #48 so far had to be found by reading code,
 * because a misconfiguration the wizard applied left no trace anywhere.
 *
 * error_log() is called unconditionally on purpose — the same choice already
 * made for the footer-location diagnostic — while the option gives a durable
 * record that survives the request.
 *
 * @param string      $step      Where the warning came from (step or resolver
 *                               name). Stored verbatim, so it stays a stable
 *                               key to read back and assert on.
 * @param string      $message   What went wrong.
 * @param string|null $log_label Optional longer phrasing for the error_log line
 *                               only. Defaults to $step.
 * @return void
 */
function contai_record_site_warning( string $step, string $message, ?string $log_label = null ): void {
	$message = substr( $message, 0, 500 );
	$label   = $log_label ?? $step;

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log( "[ContAI] WARNING: {$label}: {$message}" );

	$warnings = get_option( CONTAI_SITE_WARNINGS_OPTION, array() );
	if ( ! is_array( $warnings ) ) {
		$warnings = array();
	}

	$warnings[] = array(
		'step'      => $step,
		'message'   => $message,
		'timestamp' => gmdate( 'c' ),
	);

	// FIFO: drop the oldest rather than letting the option grow without bound
	// across re-executions.
	while ( count( $warnings ) > CONTAI_SITE_WARNINGS_MAX ) {
		array_shift( $warnings );
	}

	update_option( CONTAI_SITE_WARNINGS_OPTION, $warnings );
}
