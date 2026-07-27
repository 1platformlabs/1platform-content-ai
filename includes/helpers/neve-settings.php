<?php
/**
 * Neve theme settings helpers.
 *
 * @package 1Platform_Content_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Theme mod Neve stores its footer builder layout under.
 *
 * Neve reads it itself as a JSON *string*, falling back to the layout that
 * neve_hfg_footer_settings() returns (neve 4.2.9:
 * header-footer-grid/functions-template.php:271,
 * `get_theme_mod( 'hfg_footer_layout_v2', wp_json_encode( neve_hfg_footer_settings()['builder'] ) )`).
 *
 * We deliberately do NOT call neve_hfg_footer_settings() to obtain that default.
 * The site wizard reaches this code in the same request as switch_theme(), and
 * switch_theme() does not load the newly activated theme's functions.php — so
 * none of Neve's own functions exist in the window where we need to write. This
 * is the same constraint documented at length in astra-settings.php.
 *
 * Theme mods are per-stylesheet and switch_theme() has already updated the
 * `stylesheet` option by the time we run, so set_theme_mod() lands on
 * `theme_mods_neve`. Measured on a live install inside that exact window:
 * switch_theme('neve') + set_theme_mod() in one request writes to
 * theme_mods_neve and leaves theme_mods_astra untouched.
 */
const CONTAI_NEVE_FOOTER_LAYOUT_MOD = 'hfg_footer_layout_v2';

/**
 * Neve's own id for the footer navigation component.
 *
 * neve 4.2.9: header-footer-grid/Core/Components/NavFooter.php:27,
 * `const COMPONENT_ID = 'footer-menu';`. The component renders the menu bound to
 * the theme's `footer` nav location, which is what this plugin already binds.
 */
const CONTAI_NEVE_FOOTER_MENU_COMPONENT = 'footer-menu';

/**
 * Row and slot the component is placed in.
 *
 * `bottom` is Neve's footer bar — the strip that carries the copyright — which
 * is where legal links belong, and the counterpart of the `below_1` zone the
 * Astra placement uses. Measured on a live install with the menu already bound:
 * the theme default renders 0 legal links, and the same component placed in
 * bottom.left renders both (DOM 12 133 B -> 14 458 B, HTTP 200). main.left and
 * top.left also render; bottom was chosen for position, not for reachability.
 */
const CONTAI_NEVE_FOOTER_MENU_ROW  = 'bottom';
const CONTAI_NEVE_FOOTER_MENU_SLOT = 'left';

/**
 * Neve's own default footer builder layout.
 *
 * Transcribed from the value neve_hfg_footer_settings()['builder'] produces on
 * neve 4.2.9, read back off a live install rather than written from the theme
 * source by hand. Every slot is empty — which is precisely why a Neve site shows
 * no footer menu no matter how correctly the nav location is bound (#48).
 *
 * The shape matters more than the emptiness. Neve walks the layout with
 * `array_combine( wp_list_pluck( $slot, 'id' ), array_fill( 0, count( $slot ), true ) )`
 * (header-footer-grid/Core/Builder/Abstract_Builder.php:898), so a slot entry
 * without an `id` key makes wp_list_pluck() return fewer elements than the slot
 * holds and array_combine() raises a ValueError — an uncaught fatal on every
 * page of the site, fired from wp_head. A hand-built payload did exactly that
 * during this investigation. See contai_neve_footer_slot_entries_are_well_formed().
 *
 * @return array<string, array<string, array<string, array<int, array<string, string>>>>> The default layout.
 */
function contai_neve_default_footer_builder(): array {
	$slots = array(
		'left'    => array(),
		'c-left'  => array(),
		'center'  => array(),
		'c-right' => array(),
		'right'   => array(),
	);

	$rows = array(
		'top'    => $slots,
		'main'   => $slots,
		'bottom' => $slots,
	);

	return array(
		'desktop' => $rows,
		'mobile'  => $rows,
	);
}

/**
 * Whether every slot entry in a builder payload carries an `id` key.
 *
 * This is the invariant Neve's own component walk depends on, pinned here so a
 * change to what we persist fails a test instead of taking a live site down with
 * a ValueError out of Abstract_Builder.php:898.
 *
 * Pure function: no WordPress calls, so it is directly unit-testable.
 *
 * @param mixed $layout Decoded builder payload.
 * @return bool True when no slot holds an entry missing `id`.
 */
function contai_neve_footer_slot_entries_are_well_formed( $layout ): bool {
	if ( ! is_array( $layout ) ) {
		return false;
	}

	foreach ( $layout as $rows ) {
		if ( ! is_array( $rows ) ) {
			return false;
		}
		foreach ( $rows as $slots ) {
			if ( ! is_array( $slots ) ) {
				return false;
			}
			foreach ( $slots as $components ) {
				if ( ! is_array( $components ) ) {
					return false;
				}
				foreach ( $components as $component ) {
					if ( ! is_array( $component ) || ! isset( $component['id'] ) ) {
						return false;
					}
				}
			}
		}
	}

	return true;
}

/**
 * Add the footer menu component to a Neve builder layout, if it is not already in it.
 *
 * Returns null when there is nothing to do — the component is already placed
 * somewhere — so the caller can skip the write rather than persist an identical
 * payload. A layout that already mentions the component is left alone even if it
 * sits in a row the site owner moved it to: relocating someone's component
 * without saying so is the kind of silent act on another party's data this issue
 * is about.
 *
 * Accepts the raw theme mod, which Neve stores as a JSON string, and also
 * tolerates an already-decoded array or a missing value.
 *
 * Pure function: no WordPress calls, so it is directly unit-testable.
 *
 * @param mixed $current Current value of Neve's footer layout theme mod.
 * @return array<string, mixed>|null Payload to persist, or null when unchanged.
 */
function contai_neve_footer_builder_with_menu( $current ): ?array {
	if ( is_string( $current ) ) {
		$decoded = json_decode( $current, true );
		$layout  = is_array( $decoded ) ? $decoded : array();
	} elseif ( is_array( $current ) ) {
		$layout = $current;
	} else {
		$layout = array();
	}

	// Already placed anywhere in the layout, on either device → leave it alone.
	foreach ( $layout as $rows ) {
		if ( ! is_array( $rows ) ) {
			continue;
		}
		foreach ( $rows as $slots ) {
			if ( ! is_array( $slots ) ) {
				continue;
			}
			foreach ( $slots as $components ) {
				if ( ! is_array( $components ) ) {
					continue;
				}
				foreach ( $components as $component ) {
					if ( is_array( $component )
						&& isset( $component['id'] )
						&& CONTAI_NEVE_FOOTER_MENU_COMPONENT === $component['id'] ) {
						return null;
					}
				}
			}
		}
	}

	// Restore any device or row the saved payload is missing, so what we persist
	// is a complete structure Neve can read back.
	foreach ( contai_neve_default_footer_builder() as $device => $rows ) {
		if ( ! isset( $layout[ $device ] ) || ! is_array( $layout[ $device ] ) ) {
			$layout[ $device ] = $rows;
			continue;
		}
		foreach ( $rows as $row => $slots ) {
			if ( ! isset( $layout[ $device ][ $row ] ) || ! is_array( $layout[ $device ][ $row ] ) ) {
				$layout[ $device ][ $row ] = $slots;
			}
		}
	}

	// Both devices: Neve emits a separate markup tree per device, so placing the
	// component on desktop only leaves phones exactly as broken as before — the
	// failure this issue's mobile half was about.
	foreach ( array_keys( contai_neve_default_footer_builder() ) as $device ) {
		$slot = isset( $layout[ $device ][ CONTAI_NEVE_FOOTER_MENU_ROW ][ CONTAI_NEVE_FOOTER_MENU_SLOT ] )
			&& is_array( $layout[ $device ][ CONTAI_NEVE_FOOTER_MENU_ROW ][ CONTAI_NEVE_FOOTER_MENU_SLOT ] )
				? $layout[ $device ][ CONTAI_NEVE_FOOTER_MENU_ROW ][ CONTAI_NEVE_FOOTER_MENU_SLOT ]
				: array();

		$slot[] = array( 'id' => CONTAI_NEVE_FOOTER_MENU_COMPONENT );

		$layout[ $device ][ CONTAI_NEVE_FOOTER_MENU_ROW ][ CONTAI_NEVE_FOOTER_MENU_SLOT ] = array_values( $slot );
	}

	return $layout;
}

/**
 * Make sure Neve will actually RENDER the footer menu we just bound.
 *
 * Same half-finished job as Astra, one theme over: WordPress reports the `footer`
 * location as taken, the menu holds its items, and Neve emits nothing because the
 * footer-menu component was never placed in its builder layout. Measured live
 * before this was written — bound menu, default layout, 0 legal links on the page.
 *
 * @param string $location Nav menu location the footer menu was bound to.
 * @return void
 */
function contai_neve_ensure_footer_menu_renders( string $location ): void {
	unset( $location );

	$layout = contai_neve_footer_builder_with_menu(
		get_theme_mod( CONTAI_NEVE_FOOTER_LAYOUT_MOD, null )
	);

	if ( null === $layout ) {
		return;
	}

	set_theme_mod( CONTAI_NEVE_FOOTER_LAYOUT_MOD, wp_json_encode( $layout ) );
}
