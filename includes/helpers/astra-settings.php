<?php
/**
 * Astra theme settings helpers.
 *
 * @package 1Platform_Content_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
