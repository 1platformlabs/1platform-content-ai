<?php
/**
 * Theme settings helpers for themes that do NOT store settings in theme mods,
 * or that store a setting inside a structured array rather than a scalar.
 *
 * Companion to astra-settings.php. Same root cause (#48): the site wizard wrote
 * theme configuration with set_theme_mod() and a guessed key name, which is a
 * silent no-op whenever the theme reads from somewhere else (or under another
 * name). No error, no log, no effect — which is why the issue kept reopening.
 *
 * @package 1Platform_Content_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option name GeneratePress stores ALL of its theme settings under.
 *
 * GeneratePress reads every one of these through generate_get_option(), which
 * resolves against this option and never against theme mods (GeneratePress
 * 3.6.1: inc/theme-functions.php:20-33 —
 *   wp_parse_args( get_option( 'generate_settings', array() ), $defaults )).
 * Its only get_theme_mod() calls are for WordPress core's 'custom_logo' and for
 * legacy font_* typography migration keys.
 *
 * As with Astra, we talk to the option directly instead of calling
 * generate_get_option(): the wizard reaches this code through
 * contai_install_theme() -> switch_theme(), which does not load the newly
 * activated theme's functions.php in the same request, so the theme's own
 * helpers do not exist yet at the point where we need to write.
 */
const CONTAI_GENERATEPRESS_SETTINGS_OPTION = 'generate_settings';

/**
 * Merge settings into an existing GeneratePress settings payload.
 *
 * GeneratePress keeps its customizer values in ONE option array, and
 * generate_get_option() only backfills MISSING keys from defaults — it cannot
 * recover a value we dropped. A blind overwrite would therefore permanently
 * destroy every unrelated setting the user had. Read-merge is mandatory.
 *
 * Anything that is not an array (option absent, or corrupted to a scalar) is
 * treated as an empty baseline rather than written into.
 *
 * Pure function: no WordPress calls, so it is directly unit-testable.
 *
 * @param mixed $current  Current value of the generate_settings option.
 * @param array $settings Settings to set, keyed by GeneratePress option name.
 * @return array The merged payload to persist.
 */
function contai_generatepress_settings_merge( $current, array $settings ): array {
	$base = is_array( $current ) ? $current : array();

	foreach ( $settings as $key => $value ) {
		$base[ $key ] = $value;
	}

	return $base;
}

/**
 * Persist settings into GeneratePress's own settings option.
 *
 * @param array $settings Settings to set, keyed by GeneratePress option name.
 * @return void
 */
function contai_generatepress_apply_settings( array $settings ): void {
	if ( empty( $settings ) ) {
		return;
	}

	$current = get_option( CONTAI_GENERATEPRESS_SETTINGS_OPTION, array() );

	update_option(
		CONTAI_GENERATEPRESS_SETTINGS_OPTION,
		contai_generatepress_settings_merge( $current, $settings )
	);
}

/**
 * Enable one element inside a Blocksy "hero elements" list.
 *
 * Blocksy has no boolean breadcrumbs setting. Breadcrumbs are one entry in an
 * ordered list of page-title elements stored per prefix in the
 * '{prefix}_hero_elements' theme mod (Blocksy 2.x:
 * inc/components/hero/elements.php:70-74 -> blocksy_akg_or_customizer() ->
 * inc/helpers/options.php:99-104 blocksy_get_theme_mod( $prefix . '_' . $key )),
 * and the entry ships disabled for everything except WooCommerce products
 * (elements.php:65-68 'enabled' => $prefix === 'product').
 *
 * So the only way to turn breadcrumbs on is to write the whole ordered list
 * back with that one entry flipped. We preserve any list already stored —
 * including elements we do not know about and the user's ordering — and only
 * fall back to $fallback when nothing is stored yet. If the stored list has no
 * entry for $id at all, the entry is appended rather than dropped.
 *
 * Pure function: no WordPress calls, so it is directly unit-testable.
 *
 * @param mixed  $current  Current value of the hero elements theme mod.
 * @param string $id       Element id to enable (e.g. 'breadcrumbs').
 * @param array  $fallback Ordered list to use when nothing is stored yet.
 * @return array The element list to persist.
 */
function contai_hero_elements_enable( $current, string $id, array $fallback ): array {
	$elements = is_array( $current ) && ! empty( $current ) ? $current : $fallback;

	$found = false;

	foreach ( $elements as $index => $element ) {
		if ( is_array( $element ) && isset( $element['id'] ) && $id === $element['id'] ) {
			$elements[ $index ]['enabled'] = true;
			$found                         = true;
		}
	}

	if ( ! $found ) {
		$elements[] = array(
			'id'      => $id,
			'enabled' => true,
		);
	}

	return array_values( $elements );
}
