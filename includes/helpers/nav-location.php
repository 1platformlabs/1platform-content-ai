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

/**
 * Pick the best footer nav menu location out of a registered-menus registry.
 *
 * Used when the static footer map has no entry for the active theme, or has one
 * the theme does not register. Three of the nine supported themes register no
 * footer location at all in their free build, so this fallback is their only
 * path — its ranking has to be right.
 *
 * Patterns are tried in DESCENDING strength across the whole registry, rather
 * than walking the registry once and accepting the first location that matches
 * any pattern. That ordering matters: Kadence registers
 * primary, secondary, mobile, footer in that order, and 'secondary' is a weak
 * footer pattern, so a registry-major walk assigned the footer legal menu to
 * the theme's SECONDARY HEADER nav while a genuine 'footer' location sat two
 * entries later (#48).
 *
 * Pure function: no WordPress calls, so it is directly unit-testable.
 *
 * @param mixed $registered Result of get_registered_nav_menus().
 * @return string|null The chosen location, or null when nothing matches.
 */
function contai_match_footer_nav_location( $registered ): ?string {
	if ( ! is_array( $registered ) || empty( $registered ) ) {
		return null;
	}

	// Strongest signal first. 'secondary' stays last because several themes use
	// it for a second HEADER menu, not a footer one.
	$footer_patterns  = array( 'footer', 'bottom', 'secondary' );
	$exclude_patterns = array( 'primary', 'main', 'header', 'top', 'mobile', 'social' );

	foreach ( $footer_patterns as $pattern ) {
		foreach ( $registered as $location => $description ) {
			$loc_lower  = strtolower( (string) $location );
			$desc_lower = strtolower( (string) $description );

			// Skip primary navigation locations.
			$is_excluded = false;
			foreach ( $exclude_patterns as $exclude ) {
				if ( strpos( $loc_lower, $exclude ) !== false || strpos( $desc_lower, $exclude ) !== false ) {
					$is_excluded = true;
					break;
				}
			}
			if ( $is_excluded ) {
				continue;
			}

			if ( strpos( $loc_lower, $pattern ) !== false || strpos( $desc_lower, $pattern ) !== false ) {
				return (string) $location;
			}
		}
	}

	return null;
}
