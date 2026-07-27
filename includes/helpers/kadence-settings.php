<?php
/**
 * Kadence theme settings helpers.
 *
 * @package 1Platform_Content_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Theme mod Kadence stores its footer builder layout under.
 *
 * Kadence reads it with `kadence()->option( 'footer_items' )`
 * (kadence 1.5.2: inc/components/custom_footer/component.php:64,79,95), and
 * `option()` resolves to `get_theme_mod( $key, null )` whenever the option type
 * is `theme_mod` — which is the unfiltered default
 * (inc/components/options/component.php:4806 and :220-227,
 * `apply_filters( 'kadence_theme_option_type', 'theme_mod' )`).
 *
 * Unlike Neve, Kadence stores this as a plain array, not a JSON string.
 *
 * As with Astra, Neve and Blocksy, we deliberately do NOT call the theme's own
 * defaults to obtain the fallback: the site wizard reaches this code in the same
 * request as switch_theme(), which does not load the newly activated theme's
 * functions.php, so `kadence()` does not exist in the window where we write.
 */
const CONTAI_KADENCE_FOOTER_ITEMS_MOD = 'footer_items';

/**
 * Kadence's own id for the footer navigation element.
 *
 * `render_footer()` turns each element id straight into a template path —
 * `get_template_part( 'template-parts/footer/' . $item )`
 * (inc/components/custom_footer/component.php:79-86) — so the id is the template
 * filename: `template-parts/footer/footer-navigation.php`.
 *
 * That template fires `do_action( 'kadence_footer_navigation' )`, hooked to
 * `Kadence\footer_navigation` (inc/template-hooks.php:269), which calls
 * `wp_nav_menu()` with `theme_location = FOOTER_NAV_MENU_SLUG = 'footer'`
 * (inc/components/nav_menus/component.php:35,295-307) — the very location this
 * plugin binds the legal menu to.
 */
const CONTAI_KADENCE_FOOTER_NAV_ITEM = 'footer-navigation';

/**
 * Row and column the element is placed in.
 *
 * `bottom` is Kadence's footer bar — the strip that carries the `footer-html`
 * copyright element in the theme's own default — which is where legal links
 * belong and the counterpart of Astra's `below_1` zone and Neve's `bottom` row.
 *
 * The column matters as much as the row, and this is the trap. `footer-row.php`
 * renders exactly `kadence()->option( 'footer_' . $row . '_columns' )` columns
 * (template-parts/footer/footer-row.php:15,31-45), and the default for
 * `footer_bottom_columns` is the string `'1'`
 * (inc/components/options/component.php:2283). Anything written to `bottom_2`
 * through `bottom_5` therefore sits outside the rendered range and is invisible —
 * the same silent no-op that made an earlier Astra placement land in `below_2`.
 * `bottom_1` is the only column guaranteed to render.
 */
const CONTAI_KADENCE_FOOTER_NAV_ROW    = 'bottom';
const CONTAI_KADENCE_FOOTER_NAV_COLUMN = 'bottom_1';

/**
 * Kadence's own default footer element layout.
 *
 * Transcribed from the `footer_items` default on kadence 1.5.2
 * (inc/components/options/component.php:1955-1976). Every column is empty except
 * `bottom_1`, which holds the copyright HTML — which is precisely why a Kadence
 * site shows no footer menu no matter how correctly the nav location is bound
 * (#48).
 *
 * @return array<string, array<string, array<int, string>>> The default payload.
 */
function contai_kadence_default_footer_items(): array {
	return array(
		'top'    => array(
			'top_1' => array(),
			'top_2' => array(),
			'top_3' => array(),
			'top_4' => array(),
			'top_5' => array(),
		),
		'middle' => array(
			'middle_1' => array(),
			'middle_2' => array(),
			'middle_3' => array(),
			'middle_4' => array(),
			'middle_5' => array(),
		),
		'bottom' => array(
			'bottom_1' => array( 'footer-html' ),
			'bottom_2' => array(),
			'bottom_3' => array(),
			'bottom_4' => array(),
			'bottom_5' => array(),
		),
	);
}

/**
 * Add the footer navigation element to a Kadence layout, if it is not already in it.
 *
 * Returns null when there is nothing to do — the element is already placed
 * somewhere — so the caller can skip the write rather than persist an identical
 * payload. A layout that already mentions the element is left alone even if the
 * site owner moved it to another row: relocating someone's component without
 * saying so is the kind of silent act on another party's data this issue is about.
 *
 * Pure function: no WordPress calls, so it is directly unit-testable.
 *
 * @param mixed $current Current value of Kadence's footer items theme mod.
 * @return array<string, mixed>|null Payload to persist, or null when unchanged.
 */
function contai_kadence_footer_items_with_nav( $current ): ?array {
	if ( is_string( $current ) ) {
		$decoded = json_decode( $current, true );
		$items   = is_array( $decoded ) ? $decoded : array();
	} elseif ( is_array( $current ) ) {
		$items = $current;
	} else {
		$items = array();
	}

	// Already placed in any row, any column → leave it alone.
	foreach ( $items as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		foreach ( $row as $column ) {
			if ( is_array( $column ) && in_array( CONTAI_KADENCE_FOOTER_NAV_ITEM, $column, true ) ) {
				return null;
			}
		}
	}

	// Restore any row or column the saved payload is missing, so what we persist
	// is a complete structure Kadence can read back.
	foreach ( contai_kadence_default_footer_items() as $row => $columns ) {
		if ( ! isset( $items[ $row ] ) || ! is_array( $items[ $row ] ) ) {
			$items[ $row ] = $columns;
			continue;
		}
		foreach ( $columns as $column => $elements ) {
			if ( ! isset( $items[ $row ][ $column ] ) || ! is_array( $items[ $row ][ $column ] ) ) {
				$items[ $row ][ $column ] = $elements;
			}
		}
	}

	$column = array_values( $items[ CONTAI_KADENCE_FOOTER_NAV_ROW ][ CONTAI_KADENCE_FOOTER_NAV_COLUMN ] );

	$column[] = CONTAI_KADENCE_FOOTER_NAV_ITEM;

	$items[ CONTAI_KADENCE_FOOTER_NAV_ROW ][ CONTAI_KADENCE_FOOTER_NAV_COLUMN ] = $column;

	return $items;
}

/**
 * Make sure Kadence will actually RENDER the footer menu we just bound.
 *
 * Same half-finished job as Astra, Neve and Blocksy, one theme over: WordPress
 * reports the `footer` location as taken, the menu holds its items, and Kadence
 * emits nothing because the `footer-navigation` element was never placed in its
 * footer layout. Measured live before this was written — bound menu, default
 * layout, 0 legal links on the page.
 *
 * @param string $location Nav menu location the footer menu was bound to.
 * @return void
 */
function contai_kadence_ensure_footer_menu_renders( string $location ): void {
	unset( $location );

	$items = contai_kadence_footer_items_with_nav(
		get_theme_mod( CONTAI_KADENCE_FOOTER_ITEMS_MOD, null )
	);

	if ( null === $items ) {
		return;
	}

	set_theme_mod( CONTAI_KADENCE_FOOTER_ITEMS_MOD, $items );
}
