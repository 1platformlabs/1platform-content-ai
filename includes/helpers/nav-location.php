<?php
/**
 * Nav menu location resolution helpers.
 *
 * @package 1Platform_Content_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decide whether a statically-mapped nav menu location can be trusted.
 *
 * The plugin resolves nav menu locations from hand-maintained theme maps
 * (CONTAI_THEME_NAV_LOCATION_MAP and the footer map in site-generation.php)
 * because get_registered_nav_menus() is unreliable in the cron/async context
 * the site wizard runs in — the theme's after_setup_theme hook may not have
 * fired yet, leaving the registry empty.
 *
 * That trade-off is deliberate, but it was applied unconditionally: the mapped
 * location was written to nav_menu_locations without ever checking that the
 * active theme registers it. WordPress silently ignores nav_menu_locations
 * entries for unregistered locations, so a stale or wrong map entry produced a
 * silent no-op — and, because the call sites returned early, it also made
 * their pattern-matching fallback and their diagnostic log unreachable (#48).
 *
 * So this predicate only rejects a mapped location when we hold a POPULATED
 * registry that demonstrably lacks it. An empty/unavailable registry means
 * "cannot tell", which is exactly the cron case the static maps exist for —
 * there we keep trusting the map, preserving the original behaviour.
 *
 * @param string|null $location   The statically-mapped location, if any.
 * @param mixed       $registered Result of get_registered_nav_menus().
 * @return bool True if $location should be used as-is.
 */
function contai_nav_location_is_usable( ?string $location, $registered ): bool {
	if ( null === $location || '' === $location ) {
		return false;
	}

	// Registry unavailable (cron/async, theme hooks not fired) → cannot
	// disprove the map, so keep trusting it.
	if ( ! is_array( $registered ) || empty( $registered ) ) {
		return true;
	}

	return array_key_exists( $location, $registered );
}
