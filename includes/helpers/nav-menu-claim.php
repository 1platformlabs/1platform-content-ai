<?php
/**
 * Survive core's post-theme-switch nav menu remapping (#48).
 *
 * @package 1Platform_Content_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/site-warnings.php';

/**
 * Option holding the nav menu locations this plugin assigned, and the theme it
 * assigned them for.
 *
 * Shape: array( 'stylesheet' => 'astra', 'locations' => array( 'primary' => 12 ) )
 *
 * Read it off a generated site with:
 *   wp option get contai_nav_menu_location_claim
 */
const CONTAI_NAV_MENU_CLAIM_OPTION = 'contai_nav_menu_location_claim';

/**
 * Assign a nav menu to a location AND record that we did so.
 *
 * Every previous root cause on #48 was "the wizard wrote the wrong location
 * key". This one is the opposite and survived all of those fixes: the wizard
 * writes the RIGHT key, WordPress stores it, and then core overwrites it on the
 * next page load. It is the residue that made the menu still look missing after
 * five rounds of map corrections.
 *
 * The chain, read from core 7.0.2:
 *
 *   1. contai_install_theme() calls switch_theme() (site-generation.php:491).
 *      switch_theme() snapshots the OUTGOING theme's assignments into the
 *      'theme_switch_menu_locations' option (theme.php:785-786) and sets
 *      'theme_switched' (theme.php:840).
 *   2. Still in that same request, the wizard assigns its menus — the primary
 *      menu in ContaiMainMenuManager and the legal footer menu in
 *      contai_assign_footer_menu_location().
 *   3. On the NEXT request, check_theme_switched() (init, priority 99 via
 *      default-filters.php:367) fires do_action('after_switch_theme')
 *      (theme.php:3502/3506). _wp_menus_changed() is hooked to it at the
 *      default priority (default-filters.php:371) and does:
 *
 *          $old = get_option('theme_switch_menu_locations');   // nav-menu.php:1215
 *          $new = get_nav_menu_locations();                    // 1216 — our work
 *          set_theme_mod('nav_menu_locations',
 *              wp_map_nav_menu_locations($new, $old));         // 1217-1219
 *
 *      and wp_map_nav_menu_locations() lets the OLD value win unconditionally
 *      for any location the old theme also had — 'Map locations with the same
 *      slug', nav-menu.php:1249-1254 — or pops it in wholesale when both themes
 *      have exactly one location (nav-menu.php:1240-1244).
 *
 * Six of the nine themes in CONTAI_THEME_NAV_LOCATION_MAP use the slug
 * 'primary', so the same-slug branch is the common path. The precondition is
 * that the outgoing theme had at least one assigned location — not true of a
 * stock block-theme install, but true of any site that already ran a classic
 * theme with a menu, and GUARANTEED on every wizard re-run, because run 1
 * leaves exactly those assignments for run 2's switch_theme() to snapshot.
 *
 * Symptom: generation reports success, the database is briefly correct, and the
 * first front-end load silently swaps the header menu back to the site's
 * previous one — or leaves the location unbound, so the theme falls back to
 * wp_page_menu() and renders the generated legal pages as the main navigation.
 * That is the exact symptom this issue opened with.
 *
 * Note contai_nav_registry_is_stale() (nav-location.php) already reads
 * 'theme_switched' and its comment traces check_theme_switched() to
 * theme.php:3510 — it looked at the right core function and stopped one line
 * short of the do_action() that causes this.
 *
 * We do not touch core's snapshot option; core deletes it itself at
 * nav-menu.php:1220. We re-assert our own assignment afterwards instead, which
 * leaves every other location core mapped exactly as core mapped it.
 *
 * @param string $location Registered nav menu location slug.
 * @param int    $menu_id  Nav menu term id.
 * @return void
 */
function contai_assign_nav_menu_location( string $location, int $menu_id ): void {
	$locations              = get_nav_menu_locations();
	$locations[ $location ] = $menu_id;
	set_theme_mod( 'nav_menu_locations', $locations );

	contai_claim_nav_menu_location( $location, $menu_id );
}

/**
 * Record an assignment so it can be re-asserted after core remaps.
 *
 * Keyed by stylesheet on purpose: a claim made for Astra must not be replayed
 * onto a theme the site owner chose later.
 *
 * @param string $location Registered nav menu location slug.
 * @param int    $menu_id  Nav menu term id.
 * @return void
 */
function contai_claim_nav_menu_location( string $location, int $menu_id ): void {
	$stylesheet = get_stylesheet();
	$claim      = get_option( CONTAI_NAV_MENU_CLAIM_OPTION, array() );

	if ( ! is_array( $claim )
		|| ! isset( $claim['stylesheet'] )
		|| $claim['stylesheet'] !== $stylesheet
		|| ! isset( $claim['locations'] )
		|| ! is_array( $claim['locations'] )
	) {
		$claim = array(
			'stylesheet' => $stylesheet,
			'locations'  => array(),
		);
	}

	$claim['locations'][ $location ] = $menu_id;

	update_option( CONTAI_NAV_MENU_CLAIM_OPTION, $claim );
}

/**
 * Re-apply this plugin's nav menu assignments after core has remapped them.
 *
 * Hooked to 'after_switch_theme' at priority 11, i.e. strictly after
 * _wp_menus_changed() (registered at the default priority 10 in
 * default-filters.php:371). This runs on the request AFTER switch_theme(), so
 * unlike the wizard's own request the nav menu registry here genuinely
 * describes the active theme — get_registered_nav_menus() can be trusted, and
 * is used to skip any location the new theme does not register (WordPress
 * silently drops those).
 *
 * Deliberately conservative — a claim is skipped when:
 *   - it was recorded for a different stylesheet,
 *   - the active theme does not register that location,
 *   - the menu itself no longer exists (the owner deleted it),
 *   - core already mapped our menu into that location anyway.
 *
 * @return void
 */
function contai_reassert_nav_menu_locations(): void {
	$claim = get_option( CONTAI_NAV_MENU_CLAIM_OPTION, array() );

	if ( ! is_array( $claim ) || empty( $claim['locations'] ) || ! is_array( $claim['locations'] ) ) {
		return;
	}

	if ( ! isset( $claim['stylesheet'] ) || $claim['stylesheet'] !== get_stylesheet() ) {
		return;
	}

	$registered = get_registered_nav_menus();
	$registered = is_array( $registered ) ? $registered : array();
	$locations  = get_nav_menu_locations();
	$locations  = is_array( $locations ) ? $locations : array();
	$restored   = array();

	foreach ( $claim['locations'] as $location => $menu_id ) {
		$menu_id = (int) $menu_id;

		if ( ! isset( $registered[ $location ] ) ) {
			continue;
		}

		if ( ! wp_get_nav_menu_object( $menu_id ) ) {
			continue;
		}

		if ( isset( $locations[ $location ] ) && (int) $locations[ $location ] === $menu_id ) {
			continue;
		}

		$locations[ $location ] = $menu_id;
		$restored[]             = $location;
	}

	if ( empty( $restored ) ) {
		return;
	}

	set_theme_mod( 'nav_menu_locations', $locations );

	contai_record_site_warning(
		'nav location remap',
		sprintf(
			'WordPress remapped %s to the previous theme after the theme switch; the generated menu was re-assigned.',
			implode( ', ', $restored )
		)
	);
}

/**
 * Wire the re-assert to the hook core remaps on.
 *
 * Extracted so the WIRING itself is testable. Registering at the wrong priority
 * — or not at all — leaves contai_reassert_nav_menu_locations() perfectly
 * correct and never executed, which is the shape of this issue's v2.38.7 root
 * cause. A test that only calls the function directly cannot see that.
 *
 * @return void
 */
function contai_register_nav_menu_claim_hooks(): void {
	// Priority 11: strictly after _wp_menus_changed(), registered at the
	// default 10 in wp-includes/default-filters.php:371.
	add_action( 'after_switch_theme', 'contai_reassert_nav_menu_locations', 11 );
}

contai_register_nav_menu_claim_hooks();
