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
 * Is the in-memory nav menu registry describing a theme we already left?
 *
 * The wizard switches theme and configures menus in the SAME request:
 * ContaiWebsiteGenerationService::generateCompleteWebsite() calls
 * contai_install_theme() (which ends in switch_theme()) and then
 * contai_create_footer_menu_with_legal_pages() further down the same method.
 *
 * get_registered_nav_menus() just returns the $_wp_registered_nav_menus global
 * (wp-includes/nav-menu.php:149-152), which is only ever populated by a theme
 * calling register_nav_menus() from after_setup_theme. switch_theme()
 * (wp-includes/theme.php:757+) updates options and resets the template globals,
 * but it cannot load the incoming theme's functions.php in the same request, so
 * it never repopulates that global. The registry therefore still describes the
 * OUTGOING theme.
 *
 * That is worse than an empty registry, because a populated one looks
 * authoritative: contai_nav_location_is_usable() would reject the incoming
 * theme's correct mapped location for not appearing in the outgoing theme's
 * registry, and contai_match_footer_nav_location() would then pick a location
 * out of the outgoing theme — an entry the active theme does not register,
 * which WordPress silently drops. The guard added to stop silent no-ops would
 * cause one (#48).
 *
 * Core marks this for us. switch_theme() sets the 'theme_switched' option to
 * the outgoing stylesheet (theme.php:840), and nothing clears it until the NEXT
 * request, where check_theme_switched() runs on init priority 99
 * (default-filters.php:367) and sets it back to false (theme.php:3510). So a
 * truthy 'theme_switched' means "switch_theme() ran in this request and the
 * registry is stale"; on any later request it is false and the registry is
 * genuinely the active theme's.
 *
 * @return bool True when the registry cannot be trusted to describe the active theme.
 */
function contai_nav_registry_is_stale(): bool {
	return (bool) get_option( 'theme_switched' );
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
 * @param string|null $location    The statically-mapped location, if any.
 * @param mixed       $registered  Result of get_registered_nav_menus().
 * @param bool        $stale       True when the registry describes the theme we
 *                                 just switched away from (see
 *                                 contai_nav_registry_is_stale()). Then it can
 *                                 neither confirm nor disprove the map.
 * @return bool True if $location should be used as-is.
 */
function contai_nav_location_is_usable( ?string $location, $registered, bool $stale = false ): bool {
	if ( null === $location || '' === $location ) {
		return false;
	}

	// Registry unavailable (cron/async, theme hooks not fired) or describing
	// the outgoing theme → cannot disprove the map, so keep trusting it. The
	// maps are hand-verified against each theme's register_nav_menus() call,
	// which is exactly the evidence a stale registry cannot supply.
	if ( $stale || ! is_array( $registered ) || empty( $registered ) ) {
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
 * @param bool  $stale      True when the registry describes the outgoing theme
 *                          (see contai_nav_registry_is_stale()). Matching
 *                          against it would bind the legal menu to a location
 *                          the ACTIVE theme does not register, which WordPress
 *                          drops silently — strictly worse than not binding,
 *                          because it leaves no trace. Return null instead and
 *                          let the caller record a warning.
 * @return string|null The chosen location, or null when nothing matches.
 */
function contai_match_footer_nav_location( $registered, bool $stale = false ): ?string {
	if ( $stale || ! is_array( $registered ) || empty( $registered ) ) {
		return null;
	}

	// Strongest signal first. 'secondary' stays last because several themes use
	// it for a second HEADER menu, not a footer one.
	$footer_patterns  = array( 'footer', 'bottom', 'secondary' );
	$exclude_patterns = array( 'primary', 'main', 'header', 'top', 'mobile', 'social' );

	// Locations this plugin registers itself are never candidates. The contract
	// of this matcher is "find a location the ACTIVE THEME renders", and a
	// location registered by the plugin is by definition not rendered by the
	// theme, so assigning the legal menu to one is a silent no-op.
	//
	// This is load-bearing, not defensive. The plugin registers
	// 'contai-footer-bottom' on init (cookie-notice-helper.php), which is the
	// only occurrence of that slug in the repo: nothing assigns a menu to it
	// and no template renders it. It contains 'footer' — the STRONGEST pattern
	// — and matches none of the exclusions above, so it was selectable here.
	// It wins whenever the active theme registers no location containing
	// 'footer', which is exactly the three themes (generatepress, sydney,
	// colormag) that were deliberately dropped from the static footer map so
	// they would rely on this fallback (#48).
	$plugin_owned_prefix = 'contai-';

	foreach ( $footer_patterns as $pattern ) {
		foreach ( $registered as $location => $description ) {
			$loc_lower  = strtolower( (string) $location );
			$desc_lower = strtolower( (string) $description );

			// Skip locations this plugin registers (see above).
			if ( strpos( $loc_lower, $plugin_owned_prefix ) === 0 ) {
				continue;
			}

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
