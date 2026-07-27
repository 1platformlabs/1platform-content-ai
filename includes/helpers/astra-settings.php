<?php
/**
 * Astra theme settings helpers.
 *
 * @package 1Platform_Content_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// contai_astra_ensure_footer_menu_renders() records a diagnostic. Required here
// rather than relied on from the caller so this file stays self-contained: a
// branch that only resolves when some other file happened to load first is
// order-dependent, and an order-dependent branch is how this issue's v2.38.7
// root cause stayed invisible.
require_once __DIR__ . '/site-warnings.php';

/**
 * Option name Astra stores ALL of its theme settings under.
 *
 * Astra defines this itself as ASTRA_THEME_SETTINGS in its functions.php:
 *   define( 'ASTRA_THEME_SETTINGS', 'astra-settings' );
 *
 * We deliberately do NOT reference that constant (nor astra_get_option() /
 * astra_update_option()): the site wizard calls contai_install_theme(), which
 * ends in switch_theme(). switch_theme() only updates the active-theme option —
 * it does not load the newly activated theme's functions.php in the same
 * request. On a fresh install Astra was not the active theme when the request
 * booted, so none of Astra's helpers or constants exist at the point where we
 * need to write its settings. Talking to the option directly is the only thing
 * that works in that window, and it is exactly what astra_update_option() does
 * internally anyway.
 */
const CONTAI_ASTRA_SETTINGS_OPTION = 'astra-settings';

/**
 * Merge settings into an existing Astra settings payload.
 *
 * Astra keeps every customizer value in ONE serialized associative array, so a
 * blind overwrite would wipe unrelated settings. This mirrors the read-merge-
 * write that astra_update_option() performs, and treats anything that is not an
 * array (option absent, or corrupted to a scalar/string) as an empty baseline
 * rather than trying to write into it.
 *
 * Pure function: no WordPress calls, so it is directly unit-testable.
 *
 * @param mixed $current  Current value of the astra-settings option.
 * @param array $settings Settings to set, keyed by Astra option name.
 * @return array The merged payload to persist.
 */
function contai_astra_settings_merge( $current, array $settings ): array {
	$base = is_array( $current ) ? $current : array();

	foreach ( $settings as $key => $value ) {
		$base[ $key ] = $value;
	}

	return $base;
}

/**
 * Persist settings into Astra's own settings option.
 *
 * Why this exists at all (#48): the wizard previously wrote Astra's settings
 * with set_theme_mod(), which lands in the theme_mods_astra option. Astra never
 * reads that — every one of its settings is read through astra_get_option(),
 * which resolves against the astra-settings option (Astra 4.13.6:
 * inc/core/common-functions.php:558 -> Astra_Theme_Options::get_options() ->
 * inc/core/class-astra-theme-options.php:933 get_option( ASTRA_THEME_SETTINGS )).
 * Astra's only get_theme_mod() calls are for WordPress core's 'custom_logo'.
 * So those writes were silent no-ops: no error, no log, no effect.
 *
 * @param array $settings Settings to set, keyed by Astra option name.
 * @return void
 */
function contai_astra_apply_settings( array $settings ): void {
	if ( empty( $settings ) ) {
		return;
	}

	$current = get_option( CONTAI_ASTRA_SETTINGS_OPTION, array() );

	update_option(
		CONTAI_ASTRA_SETTINGS_OPTION,
		contai_astra_settings_merge( $current, $settings )
	);
}

/**
 * Astra option holding the footer builder layout, and the menu component slug.
 *
 * Astra's footer is a BUILDER: the row template walks the layout and emits one
 * `do_action()` per component slug it finds
 * (astra 4.13.6: template-parts/footer/builder/components.php:152-158, the
 * `case 'menu':` arm firing `astra_footer_menu`). The nav menu itself is only
 * rendered from inside that arm, by Astra_Footer_Menu_Component::menu_markup()
 * (inc/builder/type/footer/menu/class-astra-footer-menu-component.php:91-104).
 *
 * So `has_nav_menu( 'footer_menu' )` being true is NECESSARY BUT NOT SUFFICIENT:
 * with the slug absent from the layout, the component never runs and the bound
 * menu renders nowhere — which is this issue's signature failure, one layer
 * further downstream than the seven before it (#48).
 */
const CONTAI_ASTRA_FOOTER_ITEMS_KEY      = 'footer-desktop-items';
const CONTAI_ASTRA_FOOTER_MENU_COMPONENT = 'menu';

/**
 * The zone the menu component is placed into.
 *
 * It has to be `below_1`, not merely "some empty zone". The row template stops
 * emitting columns past the row's configured column count
 * (astra 4.13.6: template-parts/footer/builder/footer-row.php:56-59,
 * `if ( $astra_builder_zones > $astra_footer_columns ) { break; }`) and the
 * below row defaults to ONE column (`hbb-footer-column` = '1',
 * inc/core/builder/class-astra-builder-options.php:590). Measured on a live
 * install: the same component in `below_2` renders nothing, in `below_1` it
 * renders. Zone 1 is the only one guaranteed to be inside the rendered range
 * whatever the column count is.
 *
 * Astra renders every component in a zone, so sharing `below_1` with the
 * default `copyright` is supported and was verified live.
 */
const CONTAI_ASTRA_FOOTER_MENU_ZONE = 'below_1';

/**
 * Astra's own default footer builder layout.
 *
 * Transcribed from Astra 4.13.6
 * (inc/core/builder/class-astra-builder-options.php:404-425). It is the
 * baseline when the site has no saved layout yet: Astra resolves that key
 * against these defaults, so persisting a partial structure would drop rows it
 * expects to find.
 *
 * @return array<string, array<string, array<int, string>>> The default layout.
 */
function contai_astra_default_footer_items(): array {
	return array(
		'above'   => array(
			'above_1' => array(),
			'above_2' => array(),
			'above_3' => array(),
			'above_4' => array(),
			'above_5' => array(),
		),
		'primary' => array(
			'primary_1' => array(),
			'primary_2' => array(),
			'primary_3' => array(),
			'primary_4' => array(),
			'primary_5' => array(),
		),
		'below'   => array(
			'below_1' => array( 'copyright' ),
			'below_2' => array(),
			'below_3' => array(),
			'below_4' => array(),
			'below_5' => array(),
		),
	);
}

/**
 * Add the menu component to a footer builder layout, if it is not already in it.
 *
 * Returns null when there is nothing to do — the component is already placed
 * somewhere — so the caller can skip the write entirely rather than persisting
 * an identical payload. A layout that already mentions the component is left
 * untouched even if it sits in an unrendered zone: that is a choice the site
 * owner made in the customizer, and silently relocating their component is the
 * kind of unannounced act on someone else's data this issue is about.
 *
 * Pure function: no WordPress calls, so it is directly unit-testable.
 *
 * @param mixed $current Current value of Astra's footer-desktop-items option.
 * @return array<string, mixed>|null Payload to persist, or null when unchanged.
 */
function contai_astra_footer_items_with_menu( $current ): ?array {
	// Type guard only. Seeding the defaults here as well would be dead code:
	// the row-restoration loop below already completes an empty or partial
	// payload, so both spellings produce byte-identical output — which is
	// exactly what a surviving mutant on this line proved.
	$items = is_array( $current ) ? $current : array();

	// Already placed anywhere in the layout → leave it alone.
	foreach ( $items as $zones ) {
		if ( ! is_array( $zones ) ) {
			continue;
		}
		foreach ( $zones as $components ) {
			if ( is_array( $components ) && in_array( CONTAI_ASTRA_FOOTER_MENU_COMPONENT, $components, true ) ) {
				return null;
			}
		}
	}

	// Restore any row the saved payload is missing, so what we persist is a
	// complete structure Astra can read back.
	foreach ( contai_astra_default_footer_items() as $row => $zones ) {
		if ( ! isset( $items[ $row ] ) || ! is_array( $items[ $row ] ) ) {
			$items[ $row ] = $zones;
		}
	}

	$zone = isset( $items['below'][ CONTAI_ASTRA_FOOTER_MENU_ZONE ] )
		&& is_array( $items['below'][ CONTAI_ASTRA_FOOTER_MENU_ZONE ] )
			? $items['below'][ CONTAI_ASTRA_FOOTER_MENU_ZONE ]
			: array();

	$zone[] = CONTAI_ASTRA_FOOTER_MENU_COMPONENT;

	$items['below'][ CONTAI_ASTRA_FOOTER_MENU_ZONE ] = array_values( $zone );

	return $items;
}

/**
 * Make sure the theme will actually RENDER the footer menu we just bound.
 *
 * Binding a nav menu location is where this plugin's footer work used to stop.
 * On a builder-driven theme that is only half the job, and the missing half is
 * invisible: WordPress reports the location as taken, the menu holds its items,
 * and the theme emits nothing because the component was never placed in the
 * layout (see CONTAI_ASTRA_FOOTER_ITEMS_KEY).
 *
 * Only Astra is handled, deliberately. Astra is this plugin's default theme
 * (`get_option( 'contai_wordpress_theme', 'astra' )`), and its layout shape was
 * measured against a live install before being written here. Writing a
 * plausible-looking layout into a theme we have NOT measured is not a harmless
 * no-op the way a wrong nav slug is: a hand-built payload for Neve's
 * header-footer-grid took the whole front end down with a fatal
 * (`array_combine(): ... must have the same number of elements`,
 * neve 4.2.9 header-footer-grid/Core/Builder/Abstract_Builder.php:898). So
 * every other theme gets the diagnostic instead of a guess — the trace this
 * issue has gone without eight times.
 *
 * @param string $theme    Theme slug the wizard installed.
 * @param string $location Nav menu location the footer menu was bound to.
 * @return void
 */
function contai_astra_ensure_footer_menu_renders( string $theme, string $location ): void {
	if ( 'astra' !== $theme ) {
		contai_record_site_warning(
			'footer menu render',
			sprintf(
				"the footer menu was bound to '%s', but this plugin does not manage theme '%s' footer layout: if that theme renders its footer through a builder, the legal links stay invisible until the footer menu element is added to it",
				$location,
				$theme
			)
		);
		return;
	}

	$current = get_option( CONTAI_ASTRA_SETTINGS_OPTION, array() );
	$items   = contai_astra_footer_items_with_menu(
		is_array( $current ) && isset( $current[ CONTAI_ASTRA_FOOTER_ITEMS_KEY ] )
			? $current[ CONTAI_ASTRA_FOOTER_ITEMS_KEY ]
			: null
	);

	if ( null === $items ) {
		return;
	}

	contai_astra_apply_settings( array( CONTAI_ASTRA_FOOTER_ITEMS_KEY => $items ) );
}
