<?php
/**
 * Last-resort renderer for the generated legal footer menu (#48).
 *
 * @package 1Platform_Content_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/site-warnings.php';

/**
 * Name the wizard gives the legal footer menu.
 *
 * Shared so the creator and this fallback cannot drift apart: the fallback
 * finds the menu by this exact name.
 */
const CONTAI_FOOTER_MENU_NAME = 'Footer';

/**
 * Themes MEASURED rendering the legal menu once the nav location is bound.
 *
 * Measured on a live install (WordPress 7.0.2, es_ES, this plugin's nine mapped
 * themes, legal menu bound, counting hrefs on the DOM with <style>/<script>
 * stripped, one HTTP request per theme, homepage):
 *
 * | theme         | legal links | DOM bytes |
 * |---------------|-------------|-----------|
 * | astra         | 2           | 18233     |
 * | oceanwp       | 2           | 19593     |
 * | newsmatic     | 4           | 42848     |
 * | neve          | 4           | 14458     |
 * | blocksy       | 2           | 14141     |
 * | kadence       | 2           | 19322     |
 * | generatepress | 0           | 17194     |
 * | sydney        | 0           | 24282     |
 * | colormag      | 0           | 13380     |
 *
 * The last three cannot be fixed by binding or by placing a builder component,
 * because they register NO footer nav menu location at all in their free build:
 *
 *   generatepress 3.6.1  functions.php:56-60          -> primary
 *   sydney 2.69          functions.php:56-65          -> primary, mobile
 *                                                       (+ secondary only with
 *                                                        the hf-builder module)
 *   colormag 4.2.1       inc/core/class-colormag-after-setup-theme.php:315-320
 *                                                     -> primary, menu-secondary
 *
 * There is nothing to bind and nothing to place, so those sites shipped with the
 * legal pages reachable from no footer at all. This file renders them instead.
 *
 * An allow-list — rather than "is the menu bound to a location the theme
 * registers?" — because that question is measurably unreliable:
 *
 *   - The plugin registers 'contai-footer-bottom' itself
 *     (includes/admin/content-generator/helpers/cookie-notice-helper.php:78) and
 *     the legal menu is bound to it on ALL NINE themes, while nothing in the
 *     repo renders it. "Bound to a registered location" is therefore always true.
 *   - Core re-binds on every theme switch. wp_map_nav_menu_locations() puts
 *     'secondary', 'menu-2', 'footer', 'subsidiary' and 'bottom' in one
 *     "educated guess" group (wp-includes/nav-menu.php, $common_slug_groups),
 *     so switching Astra -> ColorMag maps Astra's 'secondary_menu' onto
 *     ColorMag's 'menu-secondary'. Measured on the live install: ColorMag ends
 *     up with the legal menu bound to 'menu-secondary', has_nav_menu() returns
 *     true, and the front end still renders ZERO legal links, because
 *     'menu-secondary' is only ever emitted by
 *     template-parts/header-builder-elements/secondary-menu.php — a HEADER
 *     builder element that is not in the default layout.
 *
 * @var string[]
 */
const CONTAI_THEMES_RENDERING_BOUND_FOOTER_MENU = array(
	'astra',
	'oceanwp',
	'newsmatic',
	'neve',
	'blocksy',
	'kadence',
);

/**
 * Was this theme measured rendering the legal menu from its bound location?
 *
 * Pure function: no WordPress calls, so it is directly unit-testable.
 *
 * @param string $stylesheet Theme slug (stylesheet or template).
 * @return bool True when the theme renders the bound menu on its own.
 */
function contai_theme_renders_bound_footer_menu( string $stylesheet ): bool {
	return in_array( $stylesheet, CONTAI_THEMES_RENDERING_BOUND_FOOTER_MENU, true );
}

/**
 * The legal footer menu this plugin generated, if this site has one.
 *
 * Deliberately narrow: a menu merely NAMED "Footer" is not enough, because this
 * callback runs on every front-end request of every site the plugin is active
 * on. It must carry at least one page the legal generator produced
 * (_contai_legal_source = 'contai_api', the same marker
 * contai_create_footer_menu_with_legal_pages() selects on), or this is somebody
 * else's menu and we render nothing.
 *
 * @return int Menu term id, or 0 when this site has no generated legal menu.
 */
function contai_footer_legal_menu_id(): int {
	$menu = wp_get_nav_menu_object( CONTAI_FOOTER_MENU_NAME );

	if ( ! $menu || empty( $menu->term_id ) ) {
		return 0;
	}

	$items = wp_get_nav_menu_items( $menu->term_id );

	if ( ! is_array( $items ) || empty( $items ) ) {
		return 0;
	}

	foreach ( $items as $item ) {
		if ( 'post_type' !== ( $item->type ?? '' ) || 'page' !== ( $item->object ?? '' ) ) {
			continue;
		}

		if ( 'contai_api' === get_post_meta( (int) $item->object_id, '_contai_legal_source', true ) ) {
			return (int) $menu->term_id;
		}
	}

	return 0;
}

/**
 * Render the legal footer menu for themes that cannot render it themselves.
 *
 * Hooked on wp_footer, which is the one placement hook every theme has to fire:
 * all nine mapped themes call wp_footer() from their footer template
 * (generatepress footer.php:61, sydney footer.php:22, colormag footer.php:99,
 * astra footer.php:33, neve footer.php:65, blocksy footer.php:28, kadence
 * footer.php:38, oceanwp footer.php:81, newsmatic footer.php:55), and core
 * defines it as do_action('wp_footer') in wp-includes/general-template.php.
 * That is why this fallback needs no theme-specific layout structure — the
 * failure mode of guessing one is a fatal, not a no-op (see theme-footer.php).
 *
 * @return void
 */
function contai_render_footer_legal_fallback(): void {
	// No "already rendered" static on purpose. add_action() de-duplicates the
	// same callback at the same priority, so this cannot be registered twice,
	// and a static would be request-scoped hidden state that survives between
	// tests — it would make the second assertion in any test file pass for the
	// wrong reason. Measured single-fire on all nine themes: exactly 2 legal
	// hrefs per page, never 4.
	if ( is_admin() ) {
		return;
	}

	// A child theme keeps its parent's footer templates, so check both. Missing
	// this would fire the fallback on e.g. an Astra child and duplicate links
	// the parent already renders.
	if ( contai_theme_renders_bound_footer_menu( get_stylesheet() )
		|| contai_theme_renders_bound_footer_menu( get_template() ) ) {
		return;
	}

	$menu_id = contai_footer_legal_menu_id();

	if ( 0 === $menu_id ) {
		return;
	}

	$nav = wp_nav_menu(
		array(
			'menu'                 => $menu_id,
			'container'            => 'nav',
			'container_id'         => 'contai-legal-footer',
			'container_class'      => 'contai-legal-footer',
			'container_aria_label' => __( 'Legal', '1platform-content-ai' ),
			'menu_class'           => 'contai-legal-footer__list',
			'depth'                => 1,
			'fallback_cb'          => false,
			'echo'                 => false,
		)
	);

	// wp_nav_menu() returns false when the menu resolves to nothing. Emitting the
	// container anyway would put an empty landmark in every page — a nav element
	// with no links is worse for a screen reader than no nav element.
	if ( ! is_string( $nav ) || '' === trim( $nav ) ) {
		return;
	}

	// Colours are inherited on purpose: the block sits inside the active theme's
	// footer area and must not fight its palette.
	echo '<style id="contai-legal-footer-css">'
		. '#contai-legal-footer{padding:16px 20px;font-size:.8125rem;line-height:1.6;text-align:center}'
		. '#contai-legal-footer .contai-legal-footer__list{list-style:none;margin:0;padding:0;'
		. 'display:flex;flex-wrap:wrap;gap:8px 20px;justify-content:center}'
		. '#contai-legal-footer .contai-legal-footer__list li{margin:0}'
		. '</style>';

	// Escaped by core: wp_nav_menu() runs every item through esc_url()/esc_attr()
	// and the 'nav_menu_item_title' filter chain before returning the markup.
	echo $nav; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Register the fallback on wp_footer.
 *
 * Extracted so the WIRING is testable on its own. A perfectly correct renderer
 * that is never hooked is exactly this issue's v2.38.7 shape, and a test that
 * only calls the function directly cannot see it.
 *
 * Priority 99: after the cookie notice (default 10,
 * cookie-notice-helper.php:82), so the legal links are the last thing in the
 * document rather than pushing a fixed banner around.
 *
 * @return void
 */
function contai_register_footer_legal_fallback_hooks(): void {
	add_action( 'wp_footer', 'contai_render_footer_legal_fallback', 99 );
}

contai_register_footer_legal_fallback_hooks();
