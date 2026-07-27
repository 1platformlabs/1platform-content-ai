<?php
/**
 * Blocksy theme settings helpers.
 *
 * @package 1Platform_Content_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Theme mod Blocksy stores its footer builder layout under.
 *
 * Blocksy reads it itself with
 * `blocksy_get_theme_mod( 'footer_placements', $this->get_default_value() )`
 * (blocksy 2.1.50: inc/components/builder/footer-logic.php:229-232 and again at
 * :274-277), so the value we persist is the one its renderer walks.
 *
 * As with Astra and Neve, we deliberately do NOT call the theme's own
 * `get_default_value()` to obtain the fallback. The site wizard reaches this code
 * in the same request as switch_theme(), and switch_theme() does not load the
 * newly activated theme's functions.php — Blocksy_Footer_Builder does not exist
 * in the window where we need to write. Theme mods are per-stylesheet and the
 * `stylesheet` option is already updated by then, so set_theme_mod() lands on
 * `theme_mods_blocksy`.
 */
const CONTAI_BLOCKSY_FOOTER_LAYOUT_MOD = 'footer_placements';

/**
 * Blocksy's own id for the footer menu component.
 *
 * The footer builder registers one item per directory under
 * `inc/panel-builder/footer`, with
 * `$id = str_replace( '_', '-', basename( $single_item ) )`
 * (blocksy 2.1.50: inc/components/customizer-builder.php:294), so the directory
 * `inc/panel-builder/footer/menu` is the item id `menu`.
 *
 * Its view renders `wp_nav_menu()` with `theme_location` defaulting to `footer`
 * (inc/panel-builder/footer/menu/view.php:3-5,47-53) — the very location this
 * plugin binds the legal menu to.
 */
const CONTAI_BLOCKSY_FOOTER_MENU_ITEM = 'menu';

/**
 * Row the component is placed in, and the section that actually renders.
 *
 * `bottom-row` is Blocksy's footer bar — the strip that carries the copyright
 * item in the theme's own default — which is where legal links belong and the
 * counterpart of Astra's `below_1` zone and Neve's `bottom` row.
 *
 * The column index matters as much as the row. `render_row()` renders exactly
 * `count( $row['columns'] )` columns (inc/components/builder/builder-footer-renderer.php:70-74),
 * and the theme default gives `bottom-row` a single column, so anything written
 * to a second column would sit outside the rendered range and be invisible —
 * the same silent no-op that made an earlier Astra placement land in `below_2`.
 * Column 0 is the only index guaranteed to render.
 *
 * `type-1` is the section Blocksy renders unless a filter says otherwise:
 * `get_current_section()` starts from `sections[0]` and overrides it with the
 * section whose id matches `get_filtered_section_id()`, which returns the
 * `blocksy:footer:current_section_id` filter default of `'type-1'`
 * (footer-logic.php:242-271).
 */
const CONTAI_BLOCKSY_FOOTER_MENU_ROW     = 'bottom-row';
const CONTAI_BLOCKSY_DEFAULT_SECTION_ID  = 'type-1';

/**
 * Blocksy's own default footer builder layout.
 *
 * Transcribed from `Blocksy_Footer_Builder::get_default_value()` on blocksy
 * 2.1.50 (inc/components/builder/footer-logic.php:9-70, which composes rows via
 * get_structure_for()/get_bar_structure_for() at :170-225). Only `bottom-row`
 * carries anything — the `copyright` item — which is precisely why a Blocksy site
 * shows no footer menu no matter how correctly the nav location is bound (#48).
 *
 * A column is a flat list of item id strings: `render_items_collection()` feeds
 * each entry straight to `render_single_item()`, which matches it against the
 * registered item ids (builder-footer-renderer.php:288-311). There is no nested
 * shape to get wrong here, unlike Neve's slot entries.
 *
 * @return array<string, mixed> The default payload.
 */
function contai_blocksy_default_footer_placements(): array {
	$section = static function ( string $id, array $middle_columns ): array {
		return array(
			'id'    => $id,
			'mode'  => 'columns',
			'rows'  => array(
				array(
					'id'      => 'top-row',
					'columns' => array( array(), array() ),
				),
				array(
					'id'      => 'middle-row',
					'columns' => $middle_columns,
				),
				array(
					'id'      => 'bottom-row',
					'columns' => array( array( 'copyright' ) ),
				),
			),
			'items'    => array(),
			'settings' => array(),
		);
	};

	return array(
		'current_section' => CONTAI_BLOCKSY_DEFAULT_SECTION_ID,
		'sections'        => array(
			$section( 'type-1', array( array(), array(), array() ) ),
			$section( 'type-2', array( array(), array(), array(), array() ) ),
		),
	);
}

/**
 * Index of the section Blocksy will render, mirroring get_current_section().
 *
 * Returns null when the payload carries no usable sections at all.
 *
 * Pure function: no WordPress calls, so it is directly unit-testable.
 *
 * @param array<string, mixed> $layout Decoded footer placements payload.
 * @return int|null Index into $layout['sections'], or null.
 */
function contai_blocksy_rendered_section_index( array $layout ): ?int {
	if ( ! isset( $layout['sections'] ) || ! is_array( $layout['sections'] ) || array() === $layout['sections'] ) {
		return null;
	}

	$sections = array_values( $layout['sections'] );
	$fallback = null;

	foreach ( $sections as $index => $section ) {
		if ( ! is_array( $section ) ) {
			continue;
		}

		if ( null === $fallback ) {
			$fallback = $index;
		}

		if ( isset( $section['id'] ) && CONTAI_BLOCKSY_DEFAULT_SECTION_ID === $section['id'] ) {
			return $index;
		}
	}

	return $fallback;
}

/**
 * Add the footer menu item to a Blocksy footer placements payload, if absent.
 *
 * Returns null when there is nothing to do — the item is already placed
 * somewhere — so the caller can skip the write rather than persist an identical
 * payload. A layout that already mentions the item is left alone even if the site
 * owner moved it to another row: relocating someone's component without saying so
 * is the kind of silent act on another party's data this issue is about.
 *
 * Pure function: no WordPress calls, so it is directly unit-testable.
 *
 * @param mixed $current Current value of Blocksy's footer placements theme mod.
 * @return array<string, mixed>|null Payload to persist, or null when unchanged.
 */
function contai_blocksy_footer_placements_with_menu( $current ): ?array {
	if ( is_string( $current ) ) {
		$decoded = json_decode( $current, true );
		$layout  = is_array( $decoded ) ? $decoded : array();
	} elseif ( is_array( $current ) ) {
		$layout = $current;
	} else {
		$layout = array();
	}

	// Already placed in any section, any row, any column → leave it alone.
	if ( isset( $layout['sections'] ) && is_array( $layout['sections'] ) ) {
		foreach ( $layout['sections'] as $section ) {
			if ( ! is_array( $section ) || ! isset( $section['rows'] ) || ! is_array( $section['rows'] ) ) {
				continue;
			}
			foreach ( $section['rows'] as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['columns'] ) || ! is_array( $row['columns'] ) ) {
					continue;
				}
				foreach ( $row['columns'] as $column ) {
					if ( is_array( $column ) && in_array( CONTAI_BLOCKSY_FOOTER_MENU_ITEM, $column, true ) ) {
						return null;
					}
				}
			}
		}
	}

	// A payload without usable sections cannot be patched in place — fall back to
	// the theme's own default, which we know Blocksy can read back.
	$index = contai_blocksy_rendered_section_index( $layout );

	if ( null === $index ) {
		$layout = contai_blocksy_default_footer_placements();
		$index  = contai_blocksy_rendered_section_index( $layout );
	}

	$layout['sections'] = array_values( $layout['sections'] );
	$section            = $layout['sections'][ $index ];

	if ( ! isset( $section['rows'] ) || ! is_array( $section['rows'] ) ) {
		return null;
	}

	$row_index = null;

	foreach ( array_values( $section['rows'] ) as $i => $row ) {
		if ( is_array( $row ) && isset( $row['id'] ) && CONTAI_BLOCKSY_FOOTER_MENU_ROW === $row['id'] ) {
			$row_index = $i;
			break;
		}
	}

	if ( null === $row_index ) {
		return null;
	}

	$section['rows'] = array_values( $section['rows'] );
	$row             = $section['rows'][ $row_index ];

	$columns = isset( $row['columns'] ) && is_array( $row['columns'] ) && array() !== $row['columns']
		? array_values( $row['columns'] )
		: array( array() );

	$first = is_array( $columns[0] ) ? array_values( $columns[0] ) : array();

	$first[]    = CONTAI_BLOCKSY_FOOTER_MENU_ITEM;
	$columns[0] = $first;

	$row['columns']                  = $columns;
	$section['rows'][ $row_index ]   = $row;
	$layout['sections'][ $index ]    = $section;

	return $layout;
}

/**
 * Make sure Blocksy will actually RENDER the footer menu we just bound.
 *
 * Same half-finished job as Astra and Neve, one theme over: WordPress reports the
 * `footer` location as taken, the menu holds its items, and Blocksy emits nothing
 * because the `menu` item was never placed in its footer builder layout. Measured
 * live before this was written — bound menu, default layout, 0 legal links on the
 * page.
 *
 * @param string $location Nav menu location the footer menu was bound to.
 * @return void
 */
function contai_blocksy_ensure_footer_menu_renders( string $location ): void {
	unset( $location );

	$layout = contai_blocksy_footer_placements_with_menu(
		get_theme_mod( CONTAI_BLOCKSY_FOOTER_LAYOUT_MOD, null )
	);

	if ( null === $layout ) {
		return;
	}

	set_theme_mod( CONTAI_BLOCKSY_FOOTER_LAYOUT_MOD, $layout );
}
