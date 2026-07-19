<?php
/**
 * Widget instance id allocation for the site wizard (#48).
 *
 * @package 1Platform_Content_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pick the widget instance id the wizard should write for a widget type.
 *
 * WordPress stores every instance of a widget type in ONE option keyed by
 * instance id ('widget_search' => [ 1 => …, 2 => …, '_multiwidget' => 1 ]),
 * shared by every sidebar on the site. contai_add_sidebar_widgets() used to
 * rebuild each of those options from scratch with a hardcoded instance id of 1
 * and update_option() it, which destroys every instance the site already had:
 * other sidebars keep referencing 'search-2', 'recent-posts-3' and so on, but
 * their settings are gone, so they render with defaults or not at all.
 *
 * Two things are needed to fix that without breaking re-execution:
 *
 * 1. Re-use the id this plugin wrote last time, so a second wizard run updates
 *    its own widget instead of appending a new one. It is recoverable without
 *    any extra bookkeeping: the wizard's own sidebar assignment list, read
 *    BEFORE it is cleared, still names them ('search-4' → 4).
 * 2. Failing that, allocate the lowest id not already taken, rather than
 *    assuming 1 is free.
 *
 * Pure function: no WordPress calls, so it is directly unit-testable.
 *
 * @param mixed  $existing_option  Current value of the widget_<type> option.
 * @param array  $previous_sidebar Widget ids previously assigned to the target
 *                                 sidebar, e.g. [ 'search-4', 'block-2' ].
 * @param string $base             Widget base, e.g. 'search', 'recent-posts'.
 * @return int The instance id to write.
 */
function contai_pick_widget_instance_id( $existing_option, array $previous_sidebar, string $base ): int {
	// 1. Re-use our own previous instance, keeping re-runs idempotent.
	foreach ( $previous_sidebar as $widget_id ) {
		if ( ! is_string( $widget_id ) ) {
			continue;
		}

		// Match '<base>-<digits>' exactly. Anchoring both ends matters:
		// 'recent-posts' must not match the id 'recent-comments-1', and
		// 'search' must not match 'my-search-1'.
		if ( preg_match( '/^' . preg_quote( $base, '/' ) . '-(\d+)$/', $widget_id, $m ) ) {
			return (int) $m[1];
		}
	}

	// 2. Otherwise take the lowest free id. '_multiwidget' is a marker, not an
	// instance, so only integer-like keys count as taken.
	$taken = array();
	if ( is_array( $existing_option ) ) {
		foreach ( array_keys( $existing_option ) as $key ) {
			if ( is_int( $key ) || ( is_string( $key ) && ctype_digit( $key ) ) ) {
				$taken[ (int) $key ] = true;
			}
		}
	}

	$id = 1;
	while ( isset( $taken[ $id ] ) ) {
		$id++;
	}

	return $id;
}
