<?php
/**
 * Sidebar resolution and widget-list merging for the site wizard (#48).
 *
 * @package 1Platform_Content_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve a sidebar id from the runtime widget-area registry.
 *
 * This is the sidebar twin of contai_nav_location_is_usable(). The wizard runs
 * contai_install_theme() — which ends in switch_theme() — and
 * contai_handle_generate_widget_submit() in the SAME request
 * (ContaiWebsiteGenerationService::generateCompleteWebsite():22 and :40), and
 * $wp_registered_sidebars is only ever populated by a theme registering its
 * widget areas on widgets_init. switch_theme() cannot load the incoming theme's
 * functions.php in that request, so mid-wizard the registry still describes the
 * theme we just switched AWAY from.
 *
 * Reading a sidebar id out of it therefore returns a widget area of the OUTGOING
 * theme. WordPress renders only the sidebars the active theme registered, so the
 * widgets land in an id nothing displays: a silent no-op, no error and no log —
 * the same failure mode as the nav locations fixed in v2.38.13 (#48).
 *
 * So the registry is consulted only when it can actually be trusted. "Cannot
 * tell" is returned as null and left for the caller to handle, rather than
 * guessed at.
 *
 * @param mixed $registered Contents of $wp_registered_sidebars.
 * @param bool  $stale      True when the registry describes the theme we just
 *                          switched away from (contai_nav_registry_is_stale()).
 * @return string|null The resolved sidebar id, or null when it cannot be told.
 */
function contai_sidebar_id_from_registry( $registered, bool $stale ): ?string {
	if ( $stale || ! is_array( $registered ) || empty( $registered ) ) {
		return null;
	}

	$priority = array( 'sidebar-1', 'sidebar', 'sidebar-primary', 'primary-sidebar', 'primary-widget-area' );
	foreach ( $priority as $id ) {
		if ( isset( $registered[ $id ] ) ) {
			return $id;
		}
	}

	foreach ( array_keys( $registered ) as $key ) {
		if ( is_string( $key ) && '' !== $key ) {
			return $key;
		}
	}

	return null;
}

/**
 * Merge the wizard's widget ids into a sidebar without discarding what is there.
 *
 * contai_add_sidebar_widgets() used to blank the target sidebar
 * ($sidebars_widgets[$id] = array()) before writing its four widgets. On a fresh
 * generation that is a no-op, but the wizard is re-runnable and the sidebar is a
 * normal, user-editable widget area: every widget the site owner had placed
 * there was silently dropped from the sidebar. v2.38.13 stopped the wizard
 * destroying widget SETTINGS (the widget_* options are read-merged now), but the
 * assignment list was still rebuilt from scratch, so those widgets simply stopped
 * rendering (#48).
 *
 * The wizard's own widgets keep their position at the top — that is the intent of
 * the generation step — and anything else is appended in its original order.
 * Ids the wizard is writing are never duplicated, which is what keeps a second
 * wizard run idempotent: contai_pick_widget_instance_id() re-uses the previous
 * run's instance ids, so those entries match and collapse instead of piling up.
 *
 * Pure function: no WordPress calls, so it is directly unit-testable.
 *
 * @param array $wizard_ids   Widget ids the wizard is assigning, in order.
 * @param array $previous_ids Widget ids already assigned to that sidebar.
 * @return array The merged assignment list.
 */
function contai_merge_sidebar_widget_ids( array $wizard_ids, array $previous_ids ): array {
	$merged = array();

	foreach ( array( $wizard_ids, $previous_ids ) as $list ) {
		foreach ( $list as $widget_id ) {
			if ( ! is_string( $widget_id ) || '' === $widget_id ) {
				continue;
			}

			if ( in_array( $widget_id, $merged, true ) ) {
				continue;
			}

			$merged[] = $widget_id;
		}
	}

	return $merged;
}
