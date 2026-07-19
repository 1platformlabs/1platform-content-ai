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
 * Wrapper class on the About Me widget markup.
 *
 * Doubles as the ownership fingerprint for the 'block' widget base, so the
 * markup and the thing that recognises the markup cannot drift apart (#48).
 */
const CONTAI_ABOUT_ME_WIDGET_CLASS = 'contai-about-me-widget';

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
 *    its own widget instead of appending a new one.
 * 2. Failing that, allocate the lowest id not already taken, rather than
 *    assuming 1 is free.
 *
 * Step 1 originally trusted the target sidebar's id list on its own — "the
 * wizard's own sidebar assignment list, read BEFORE it is cleared, still names
 * them" — and that reasoning only held while the sidebar contained nothing but
 * wizard widgets. v2.38.14 removed exactly that premise: contai_merge_sidebar_
 * widget_ids() was added to PRESERVE the site owner's ids in that list, so the
 * list now names widgets this plugin never wrote, and matching a base against
 * it adopts a stranger's instance and overwrites its settings (#48).
 *
 * That is not hypothetical for the 'block' base. WordPress seeds a stock
 * install with block widgets in the first sidebar — wp-admin/includes/
 * upgrade.php:422-448 writes widget_block[2] = '<!-- wp:search /-->' and
 * sidebars_widgets['sidebar-1'] = ['block-2','block-3','block-4'] — so on a
 * FRESH site, with no re-run required, 'block-2' matched, and the About Me card
 * was written straight over core's Search block. The merge then collapses the
 * duplicate id, so the widget does not even appear twice: it silently changes
 * identity in place.
 *
 * An id is therefore only adopted when the stored instance carries evidence
 * this plugin wrote it. $fingerprint is a substring the wizard puts in its own
 * instance (the About Me markup's wrapper class, or the widget title it sets);
 * with no fingerprint, nothing is adopted and a free id is allocated instead.
 * Allocating a spare id is at worst a duplicate widget; adopting someone else's
 * destroys their content.
 *
 * Pure function: no WordPress calls, so it is directly unit-testable.
 *
 * @param mixed  $existing_option  Current value of the widget_<type> option.
 * @param array  $previous_sidebar Widget ids previously assigned to the target
 *                                 sidebar, e.g. [ 'search-4', 'block-2' ].
 * @param string $base             Widget base, e.g. 'search', 'recent-posts'.
 * @param string $fingerprint      Substring identifying an instance this plugin
 *                                 wrote. Empty disables adoption entirely.
 * @return int The instance id to write.
 */
function contai_pick_widget_instance_id( $existing_option, array $previous_sidebar, string $base, string $fingerprint = '' ): int {
	// 1. Re-use our own previous instance, keeping re-runs idempotent — but
	// only when the stored instance proves it is ours.
	if ( '' !== $fingerprint ) {
		foreach ( $previous_sidebar as $widget_id ) {
			if ( ! is_string( $widget_id ) ) {
				continue;
			}

			// Match '<base>-<digits>' exactly. Anchoring both ends matters:
			// 'recent-posts' must not match the id 'recent-comments-1', and
			// 'search' must not match 'my-search-1'.
			if ( ! preg_match( '/^' . preg_quote( $base, '/' ) . '-(\d+)$/', $widget_id, $m ) ) {
				continue;
			}

			$candidate = (int) $m[1];

			if ( contai_widget_instance_is_ours( $existing_option, $candidate, $fingerprint ) ) {
				return $candidate;
			}
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

/**
 * Does the stored widget instance carry this plugin's fingerprint?
 *
 * The instance is an arbitrary settings array ('title' => …, 'content' => …),
 * so the values are flattened and searched rather than probing a fixed key:
 * the About Me widget is identified by markup inside 'content', the classic
 * widgets by the title the wizard set.
 *
 * A missing instance is NOT ours — an id listed in the sidebar with no stored
 * settings is a dangling reference, and claiming it would resurrect it.
 *
 * @param mixed  $existing_option Current value of the widget_<type> option.
 * @param int    $instance_id     Candidate instance id.
 * @param string $fingerprint     Substring the wizard writes into its instance.
 * @return bool
 */
function contai_widget_instance_is_ours( $existing_option, int $instance_id, string $fingerprint ): bool {
	if ( '' === $fingerprint || ! is_array( $existing_option ) ) {
		return false;
	}

	$instance = $existing_option[ $instance_id ] ?? ( $existing_option[ (string) $instance_id ] ?? null );

	if ( ! is_array( $instance ) ) {
		return false;
	}

	foreach ( $instance as $value ) {
		if ( is_string( $value ) && false !== strpos( $value, $fingerprint ) ) {
			return true;
		}
	}

	return false;
}
