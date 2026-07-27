<?php
/**
 * Cross-theme footer rendering helpers.
 *
 * @package 1Platform_Content_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/footer-legal-fallback.php';
require_once __DIR__ . '/astra-settings.php';
require_once __DIR__ . '/neve-settings.php';
require_once __DIR__ . '/blocksy-settings.php';
require_once __DIR__ . '/kadence-settings.php';

/**
 * Make sure the active theme will actually RENDER the footer menu we just bound.
 *
 * Binding a nav menu location is where this plugin's footer work used to stop.
 * On a builder-driven theme that is only half the job, and the missing half is
 * invisible: WordPress reports the location as taken, the menu holds its items,
 * and the theme emits nothing because the menu component was never placed in the
 * layout (#48).
 *
 * Only the themes whose footer layout was MEASURED on a live install are handled.
 * Writing a plausible-looking layout into a theme we have not measured is not the
 * harmless no-op a wrong nav slug is: a hand-built payload for Neve's
 * header-footer-grid took the whole front end down with an uncaught
 * `array_combine(): ... must have the same number of elements` during this
 * investigation. Every other theme therefore gets a durable diagnostic instead of
 * a guess.
 *
 * Measured on a live install (WordPress 7.0.2, this plugin's nine mapped themes,
 * legal menu bound, counting on the DOM with <style>/<script> stripped):
 *
 * | theme         | legal links with the location bound alone |
 * |---------------|-------------------------------------------|
 * | astra         | renders (via the placement below)          |
 * | oceanwp       | renders                                    |
 * | newsmatic     | renders                                    |
 * | neve          | 0 — builder, handled below                 |
 * | blocksy       | 0 — builder, handled below                 |
 * | kadence       | 0 — builder, handled below                 |
 * | generatepress | 0 — registers no footer nav location       |
 * | sydney        | 0 — registers no footer nav location       |
 * | colormag      | 0 — registers no footer nav location       |
 *
 * The last three register no footer nav location at all, so there is nothing to
 * place and nothing bound. A core widget fallback was measured and REFUTED for
 * them (it left the DOM byte-identical on every theme tried). What they get
 * instead is contai_render_footer_legal_fallback() in footer-legal-fallback.php,
 * which renders the legal menu on wp_footer — the one hook every theme has to
 * fire — plus the durable diagnostic below.
 *
 * @param string $theme    Theme slug the wizard installed.
 * @param string $location Nav menu location the footer menu was bound to.
 * @return void
 */
function contai_theme_ensure_footer_menu_renders( string $theme, string $location ): void {
	$builders = array(
		'neve'    => 'contai_neve_ensure_footer_menu_renders',
		'blocksy' => 'contai_blocksy_ensure_footer_menu_renders',
		'kadence' => 'contai_kadence_ensure_footer_menu_renders',
	);

	if ( isset( $builders[ $theme ] ) ) {
		call_user_func( $builders[ $theme ], $location );
		return;
	}

	// Astra's placement, plus the diagnostic every unmeasured theme gets.
	contai_astra_ensure_footer_menu_renders( $theme, $location );
}
