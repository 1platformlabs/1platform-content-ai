<?php

/**
 * Site Generation Helper Functions
 *
 * General-purpose functions for site generation that are not specific to any theme.
 * These functions handle theme installation, widget generation, and icon generation.
 *
 * @package Content AI
 * @since 1.9.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/nav-location.php';
require_once __DIR__ . '/nav-menu-claim.php';
require_once __DIR__ . '/sidebar-location.php';
require_once __DIR__ . '/site-warnings.php';
require_once __DIR__ . '/widget-instance.php';
require_once __DIR__ . '/astra-settings.php';
require_once __DIR__ . '/theme-settings.php';
require_once __DIR__ . '/../services/api/OnePlatformClient.php';
require_once __DIR__ . '/../services/api/OnePlatformEndpoints.php';
require_once __DIR__ . '/../providers/WebsiteProvider.php';

/**
 * Static mapping of sidebar IDs per theme.
 *
 * Used in cron/async context where $wp_registered_sidebars may be empty
 * because the theme's widgets_init hook has not fired.
 *
 * Every ID below was verified against the theme's own register_sidebar() call
 * (#48). Registering a widget against an ID the theme does not declare is a
 * silent no-op — no error, no log, no sidebar — which is the same failure mode
 * as the theme-mod keys corrected in v2.38.9.
 */
define( 'CONTAI_THEME_SIDEBAR_MAP', array(
	'astra'          => 'sidebar-1',
	'generatepress'  => 'sidebar-1',
	// Neve declares no 'sidebar-1' anywhere; its blog sidebar is 'blog-sidebar'
	// (neve 4.2.8: sidebar.php:8,14 is_active_sidebar('blog-sidebar')).
	'neve'           => 'blog-sidebar',
	'blocksy'        => 'sidebar-1',
	// Kadence registers 'sidebar-primary'/'sidebar-secondary', never 'sidebar-1'
	// (kadence 1.5.1: inc/components/layout/component.php:78-79).
	'kadence'        => 'sidebar-primary',
	'sydney'         => 'sidebar-1',
	'oceanwp'        => 'sidebar',
	'newsmatic'      => 'sidebar-1',
	// ColorMag prefixes every widget area; 'sidebar-right' does not appear in
	// the theme at all (colormag 4.2.1:
	// inc/widgets/class-colormag-widgets.php:27).
	'colormag'       => 'colormag_right_sidebar',
) );

/**
 * Static mapping of primary nav menu locations per theme.
 *
 * Used in cron/async context where get_registered_nav_menus() may return empty.
 *
 * Every location below was verified against the theme's own register_nav_menus()
 * call (#48). WordPress silently drops nav_menu_locations entries for locations
 * the active theme never registered, and a theme with no menu in its primary
 * location falls back to wp_page_menu() — which lists the published PAGES, i.e.
 * the generated legal pages. That is the original symptom this issue reported.
 */
define( 'CONTAI_THEME_NAV_LOCATION_MAP', array(
	'astra'          => 'primary',
	'generatepress'  => 'primary',
	'neve'           => 'primary',
	// Blocksy registers footer/menu_1/menu_2/menu_mobile; 'header-menu-1' is
	// not among them (blocksy 2.1.49: inc/init.php:409-412), so the wizard was
	// never assigning a menu at all and every Blocksy site fell through to the
	// wp_page_menu() page listing.
	'blocksy'        => 'menu_1',
	'kadence'        => 'primary',
	'sydney'         => 'primary',
	'oceanwp'        => 'main_menu',
	// 'menu-1' is registered, but it is Newsmatic's thin TOP bar
	// (newsmatic 1.5.0: inc/hooks/header-hooks.php:347). The main header nav
	// reads 'menu-2' (header-hooks.php:186), which is also what every one of
	// the theme's own demo imports assigns (inc/admin/assets/demos.php).
	'newsmatic'      => 'menu-2',
	'colormag'       => 'primary',
) );

/**
 * Get the primary sidebar ID for the active theme.
 *
 * Uses a static mapping to reliably resolve the sidebar ID even in
 * cron/async context where $wp_registered_sidebars may be empty.
 *
 * @return string The primary sidebar ID
 */
function contai_get_primary_sidebar_id(): string {
	$theme = get_option( 'contai_wordpress_theme', 'astra' );

	if ( isset( CONTAI_THEME_SIDEBAR_MAP[ $theme ] ) ) {
		return CONTAI_THEME_SIDEBAR_MAP[ $theme ];
	}

	// Runtime detection, but only from a registry that describes the ACTIVE
	// theme. The wizard reaches this in the same request as switch_theme(), where
	// $wp_registered_sidebars still lists the outgoing theme's widget areas, so
	// reading an id out of it hands back a sidebar the active theme does not
	// render — a silent no-op, exactly like the nav locations (#48).
	global $wp_registered_sidebars;
	$resolved = contai_sidebar_id_from_registry( $wp_registered_sidebars, contai_nav_registry_is_stale() );

	if ( null !== $resolved ) {
		return $resolved;
	}

	// Nothing trustworthy to resolve from: an unmapped theme whose widget areas
	// we cannot see. 'sidebar-1' is the WordPress convention and stays the
	// last-resort guess, but it IS a guess, so it leaves a durable trace instead
	// of failing invisibly the way this whole class of bug did.
	contai_record_site_warning(
		'sidebar id',
		sprintf(
			"theme '%s' is not in CONTAI_THEME_SIDEBAR_MAP and the widget-area registry could not be read%s; falling back to 'sidebar-1', which the theme may not register",
			$theme,
			contai_nav_registry_is_stale() ? ' (it still described the previous theme)' : ''
		)
	);

	return 'sidebar-1';
}

/**
 * Get the primary nav menu location for the active theme.
 *
 * Uses a static mapping to reliably resolve the menu location even in
 * cron/async context where get_registered_nav_menus() may return empty.
 *
 * @return string|null The primary nav menu location or null if unknown
 */
function contai_get_primary_nav_location(): ?string {
	$theme = get_option( 'contai_wordpress_theme', 'astra' );

	if ( isset( CONTAI_THEME_NAV_LOCATION_MAP[ $theme ] ) ) {
		return CONTAI_THEME_NAV_LOCATION_MAP[ $theme ];
	}

	return null;
}

/**
 * Apply theme-specific default settings after installation.
 *
 * Each theme may need different reading settings, sidebar layouts,
 * or other configuration to look good out of the box with generated content.
 *
 * @param string $theme Theme slug
 * @return void
 */
function contai_apply_theme_defaults( string $theme ): void {
	// Common defaults for all themes
	update_option( 'show_on_front', 'posts' );
	update_option( 'posts_per_page', 15 );

	switch ( $theme ) {
		case 'newsmatic':
			// A guarded call to contai_set_newsmatic_reading_defaults() stood
			// here from the initial commit. That function has never been
			// defined anywhere in this plugin's history
			// (`git log --all -S "function contai_set_newsmatic_reading_defaults"`
			// returns nothing), so function_exists() was always false and the
			// branch never ran — a dead call that read as deliberate coverage
			// of Newsmatic's reading settings (#48). The reading defaults that
			// actually apply are the show_on_front / posts_per_page writes
			// shared by every theme above.
			//
			// 'newsmatic_breadcrumb_option' does not exist in the theme (#48):
			// the only matches for that string are the customizer SECTION id
			// 'newsmatic_breadcrumb_options_section'. The setting Newsmatic
			// actually reads is 'site_breadcrumb_option'
			// (inc/extras/helpers.php:102 via newsmatic_get_customizer_option(),
			// which is get_theme_mod() with a default from
			// inc/theme-starter.php:118). It is a boolean and already defaults
			// to true, so this write matters for keeping breadcrumbs on when a
			// previous value turned them off.
			set_theme_mod( 'site_breadcrumb_option', true );
			break;

		case 'oceanwp':
			// 'ocean_blog_layout' does not exist in the theme (#48). The blog
			// and archive layout is 'ocean_blog_archives_layout'
			// (inc/helpers.php:505, inside oceanwp_post_layout()). The value
			// vocabulary is unchanged: 'right-sidebar' is valid here.
			set_theme_mod( 'ocean_blog_archives_layout', 'right-sidebar' );
			// Correct as-is: read at inc/helpers.php:2375 in
			// oceanwp_has_breadcrumbs(). Defaults to true already.
			set_theme_mod( 'ocean_breadcrumbs', true );
			break;

		case 'generatepress':
			// Two independent defects here (#48). GeneratePress never reads
			// theme mods for its settings — everything goes through
			// generate_get_option(), backed by the 'generate_settings' option
			// (inc/theme-functions.php:20-33) — so set_theme_mod() was a silent
			// no-op. And 'content_layout_setting' is not a sidebar setting at
			// all: it selects container styling, and only accepts
			// 'separate-containers' / 'one-container' (inc/customizer.php:
			// 1055-1058), so 'content-sidebar' was never a valid value either.
			//
			// The sidebar layout keys are these. They already default to
			// 'right-sidebar' (inc/defaults.php:46-48), so this write exists to
			// assert the wizard's intent on a site that was previously
			// configured otherwise, not to change a fresh install.
			contai_generatepress_apply_settings(
				array(
					'layout_setting'        => 'right-sidebar',
					'blog_layout_setting'   => 'right-sidebar',
					'single_layout_setting' => 'right-sidebar',
				)
			);
			// No breadcrumbs setting is written: GeneratePress 3.6.1 has no
			// breadcrumbs feature at all (the single 'breadcrumb' match in the
			// theme is a bbPress CSS selector in inc/plugin-compat.php:197).
			break;

		case 'colormag':
			// 'colormag_site_layout' is a pre-3.0 key that meant boxed-vs-wide
			// CONTAINER WIDTH, and today only survives inside a migration
			// teardown (inc/migration/class-colormag-migration.php:719-729).
			// Writing 'right-sidebar' there was worse than inert: it makes the
			// migration's guard truthy while matching neither branch, leaving
			// $new_container_layout undefined and corrupting
			// 'colormag_container_layout' if that migration ever runs.
			//
			// The live keys are these, and ColorMag's value vocabulary uses
			// UNDERSCORES ('right_sidebar'), not hyphens
			// (inc/customizer/options/content/blog.php:31,
			// inc/customizer/options/global/layout.php). Both are needed: the
			// blog reader's runtime default is 'no_sidebar', not 'default', so
			// it never falls through to the global value on its own
			// (inc/template-functions.php:204-206).
			set_theme_mod( 'colormag_blog_sidebar_layout', 'right_sidebar' );
			set_theme_mod( 'colormag_global_sidebar_layout', 'right_sidebar' );
			// 'colormag_breadcrumb_display' does not exist in the theme. The
			// real gate is 'colormag_breadcrumb_enable', compared loosely
			// against 1 and defaulting to 0 = OFF
			// (template-parts/hooks/header/header.php:526), so unlike the other
			// themes here this write is what actually turns breadcrumbs on.
			set_theme_mod( 'colormag_breadcrumb_enable', 1 );
			break;

		case 'astra':
			// Astra reads every one of its settings from the astra-settings
			// option via astra_get_option(), never from theme mods, so these
			// MUST NOT go through set_theme_mod() (#48).
			//
			// 'breadcrumb-position' doubles as the breadcrumbs on/off switch:
			// it defaults to 'none' (= hidden) and 'astra_entry_top' renders
			// them before the title. The separator is intentionally left alone
			// — Astra already defaults it to '\00bb' (a CSS escape for »,
			// injected into a content: rule), which is the character the
			// previous code was trying to set. Writing a raw » there would put
			// a literal glyph where a CSS escape sequence is expected.
			contai_astra_apply_settings(
				array(
					// Force right sidebar layout site-wide.
					'site-sidebar-layout'         => 'right-sidebar',
					'single-post-sidebar-layout'  => 'right-sidebar',
					'archive-post-sidebar-layout' => 'right-sidebar',
					// Show breadcrumbs before the entry title.
					'breadcrumb-position'         => 'astra_entry_top',
				)
			);
			break;

		case 'neve':
			// Neve's "Advanced Options" (on by default) route the blog/category archive
			// layout through its own mod, independent of neve_default_sidebar_layout — and
			// it defaults to full-width. Without this, the archive/home listing (where the
			// sidebar widgets from contai_add_sidebar_widgets() are supposed to render)
			// silently drops the sidebar even though single posts show it correctly (#46).
			// 'right' is the correct value here — Neve's vocabulary is
			// left/right/full-width (inc/customizer/defaults/utils.php:23-46),
			// not the 'right-sidebar' other themes use.
			set_theme_mod( 'neve_blog_archive_sidebar_layout', 'right' );
			set_theme_mod( 'neve_single_post_sidebar_layout', 'right' );
			// Static pages have their own key and also default to full-width
			// (inc/customizer/defaults/layout.php:26-34), so without this they
			// silently stayed sidebar-less (#48).
			set_theme_mod( 'neve_other_pages_sidebar_layout', 'right' );
			// Deliberately NOT set (#48):
			//
			// - 'neve_default_sidebar_layout' is only consulted when advanced
			//   layout options are OFF (inc/views/layouts/layout_sidebar.php:
			//   110-128), and they default to ON, so it was inert. Worse, it is
			//   a sentinel in Neve's new-user detection
			//   (inc/core/migration_flags.php:99-100): writing it makes Neve
			//   classify a brand-new site as a pre-v4 upgrade and silently swap
			//   in legacy defaults for blog cards, typography and element order.
			//
			// - 'neve_breadcrumbs' is not a setting at all; the only occurrences
			//   of that string are filter names. Neve free has no stored
			//   breadcrumbs toggle: is_breadcrumb_enabled()
			//   (inc/views/breadcrumbs.php:64-90) returns false unless Yoast,
			//   SEOPress, Rank Math or Breadcrumb NavXT is active. There is
			//   nothing a plugin can set here.
			break;

		case 'blocksy':
			// The key shape was right but the VALUE was not: Blocksy's
			// '{prefix}_has_sidebar' is a 'yes'/'no' switch, and position is a
			// separate mod (inc/sidebar.php:259-266). Writing 'right' happened
			// to enable the sidebar by passing the !== 'no' test, but it is not
			// a value the theme or its customizer recognises.
			set_theme_mod( 'blog_has_sidebar', 'yes' );
			set_theme_mod( 'blog_sidebar_position', 'right' );
			// 'single_has_sidebar' does not exist, and singular views never
			// reach the _has_sidebar branch at all — the single-post prefix is
			// 'single_blog_post' (inc/classes/screen-manager.php:342) and its
			// sidebar comes from a structure picker, where 'type-1' means right
			// sidebar (inc/sidebar.php:284-294,
			// inc/options/single-elements/structure.php:29-47). It defaults to
			// 'type-3' = no sidebar.
			set_theme_mod( 'single_blog_post_structure', 'type-1' );
			// 'breadcrumb_visibility' does not exist either. Breadcrumbs are an
			// entry in the per-prefix hero elements list and ship disabled for
			// everything but WooCommerce products, so the whole list has to be
			// written back with that entry flipped. The hero itself also has to
			// be on, and it defaults to 'no' specifically for the blog prefix
			// (inc/components/hero-section.php:66-76).
			set_theme_mod(
				'single_blog_post_hero_elements',
				contai_hero_elements_enable(
					get_theme_mod( 'single_blog_post_hero_elements' ),
					'breadcrumbs',
					array(
						array(
							'id'      => 'custom_title',
							'enabled' => true,
						),
						array(
							'id'      => 'custom_description',
							'enabled' => true,
						),
						array(
							'id'      => 'custom_meta',
							'enabled' => true,
						),
					)
				)
			);
			set_theme_mod( 'blog_hero_enabled', 'yes' );
			set_theme_mod(
				'blog_hero_elements',
				contai_hero_elements_enable(
					get_theme_mod( 'blog_hero_elements' ),
					'breadcrumbs',
					array(
						array(
							'id'      => 'custom_title',
							'enabled' => true,
						),
						array(
							'id'      => 'custom_description',
							'enabled' => true,
						),
					)
				)
			);
			break;

		case 'kadence':
			// Correct as-is: read as kadence()->option( $post_type . '_layout' )
			// (inc/components/layout/component.php:622), and 'right' is a valid
			// choice (inc/customizer/options/post-layout-options.php:671-691).
			set_theme_mod( 'post_layout', 'right' );
			// The bare key 'archive_layout' is never read — every occurrence is
			// part of a longer key. The blog archive reads 'post_archive_layout'
			// (inc/components/layout/component.php:838-840, $archive_type is
			// 'post_archive'), which defaults to 'normal' (#48).
			set_theme_mod( 'post_archive_layout', 'right' );
			// 'breadcrumb_enable' does not exist. Kadence breadcrumbs are a
			// title-area element toggled by an ARRAY sub-option read with
			// sub_option( $type . '_title_element_breadcrumb', 'enabled' )
			// (inc/components/entry_title/component.php:60-63 and
			// inc/components/archive_title/component.php:114-117), and both
			// default to 'enabled' => false
			// (inc/components/options/component.php:2833, :2892).
			set_theme_mod(
				'post_title_element_breadcrumb',
				array(
					'enabled'    => true,
					'show_title' => true,
				)
			);
			set_theme_mod(
				'post_archive_title_element_breadcrumb',
				array(
					'enabled'    => true,
					'show_title' => true,
				)
			);
			break;

		case 'sydney':
			// The bare key 'sidebar_position' is never read: Sydney builds its
			// mod names at runtime as 'sidebar_single_{post_type}_position'
			// (inc/extras.php:437) and uses a separate key for archives
			// (inc/extras.php:427). The literal is only a function name,
			// sydney_sidebar_position(). The value vocabulary is unchanged.
			set_theme_mod( 'sidebar_archives_position', 'sidebar-right' );
			set_theme_mod( 'sidebar_single_post_position', 'sidebar-right' );
			set_theme_mod( 'sidebar_single_page_position', 'sidebar-right' );
			// No breadcrumbs write (#48): 'enable_breadcrumbs' appears nowhere
			// in the theme, and Sydney free 2.69 has no general breadcrumbs
			// feature to enable — it is a PRO module
			// (inc/dashboard/class-dashboard-settings.php:430-439). The only
			// free breadcrumb output is an unconditional Yoast passthrough
			// (inc/extras.php:90-93) with no toggle.
			break;

		default:
			// $theme is API-supplied (SiteConfigService::…update_option
			// 'contai_wordpress_theme') and only ever passed through
			// sanitize_text_field(), which validates its characters, not its
			// membership in the theme maps. An unmapped slug reaching here gets
			// no theme configuration from the switch above AND no primary nav
			// location from contai_get_primary_nav_location(), which returns
			// null for a slug it does not know — leaving the theme to fall back
			// to wp_page_menu(), i.e. a menu of the generated legal pages,
			// which is the originally reported symptom (#48).
			//
			// Both outcomes were silent. Neither is repaired here: guessing a
			// location for an unknown theme is what the hand-verified maps
			// exist to avoid. It is only made visible.
			contai_record_site_warning(
				'theme defaults',
				sprintf(
					"theme '%s' is not in the theme maps: no theme-specific configuration and no primary nav location will be applied. Mapped themes: %s",
					$theme,
					implode( ', ', array_keys( CONTAI_THEME_NAV_LOCATION_MAP ) )
				)
			);
			break;
	}
}

/**
 * Install and activate a WordPress theme
 *
 * Downloads and installs a theme from WordPress.org theme repository if not already installed,
 * then activates it.
 *
 * @param string $theme Theme slug to install and activate
 * @return void
 */
function contai_install_theme( $theme ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/theme.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';

	$installed = wp_get_theme( $theme )->exists();

	if ( ! $installed ) {
		$api = themes_api(
			'theme_information',
			array(
				'slug'   => $theme,
				'fields' => array( 'sections' => false ),
			)
		);

		if ( is_wp_error( $api ) ) {
			contai_log( 'contai_install_theme: themes_api failed for "' . $theme . '": ' . $api->get_error_message() );
			throw new Exception( 'Theme API lookup failed for "' . $theme . '": ' . $api->get_error_message() );
		}

		$upgrader = new Theme_Upgrader( new WP_Ajax_Upgrader_Skin() );
		$result   = $upgrader->install( $api->download_link );

		if ( is_wp_error( $result ) ) {
			contai_log( 'contai_install_theme: install failed for "' . $theme . '": ' . $result->get_error_message() );
			throw new Exception( 'Theme install failed for "' . $theme . '": ' . $result->get_error_message() );
		}

		if ( $result === false ) {
			contai_log( 'contai_install_theme: install returned false for "' . $theme . '"' );
			throw new Exception( 'Theme install failed for "' . $theme . '"' );
		}
	}

	switch_theme( $theme );

	// Verify the theme was activated
	$active = wp_get_theme()->get_stylesheet();
	if ( $active !== $theme ) {
		contai_log( 'contai_install_theme: switch_theme failed. Expected "' . $theme . '", got "' . $active . '"' );
		throw new Exception( 'Theme activation failed: expected "' . $theme . '", active is "' . $active . '"' );
	}
}

/**
 * Handle widget generation submission
 *
 * Generates default widgets for the sidebar including:
 * - About Me profile card with AI-generated bio
 * - Search widget
 * - Recent Comments widget
 * - Recent Posts widget
 *
 * Also applies Newsmatic theme defaults if applicable.
 *
 * @return void
 */
function contai_handle_generate_widget_submit() {
	contai_add_sidebar_widgets();
}

/**
 * Generate site icon via WPContentAI image generation API.
 *
 * Creates a minimalist square icon and assigns it as the site icon (favicon).
 *
 * @return int|WP_Error Attachment ID on success, WP_Error on failure
 */
function contai_handle_generate_icon_submit() {
	$result = contai_generate_and_set_site_icon_from_openai();
	if ( is_wp_error( $result ) ) {
		contai_log( 'contai_handle_generate_icon_submit: Error — ' . $result->get_error_message() );
		throw new Exception( 'Icon generation failed: ' . $result->get_error_message() );
	}
}

function contai_delete_sample_content(): void {
	$hello_world_post = get_page_by_path( 'hello-world', OBJECT, 'post' );
	if ( $hello_world_post ) {
		wp_delete_post( $hello_world_post->ID, true );
	}

	$sample_page = get_page_by_path( 'sample-page', OBJECT, 'page' );
	if ( $sample_page ) {
		wp_delete_post( $sample_page->ID, true );
	}
}

/**
 * Setup general site configuration settings
 *
 * Configures site-wide settings that are not theme-specific:
 * - Permalink structure set to post name
 * - Rewrite rules flush
 * - Reading defaults setup
 * - Sample content cleanup
 *
 * This function is called during website generation to prepare the site
 * for content creation and proper URL structure.
 *
 * @return void
 */
function contai_setup_site_config() {
	// Not update_option( 'permalink_structure', … ). $wp_rewrite caches the
	// structure in a property read once in WP_Rewrite::init(), called from the
	// constructor at request start (wp-settings.php), and update_option() does
	// not resync it — core has no 'update_option_permalink_structure' hook, and
	// every core writer goes through set_permalink_structure(), which pairs the
	// option write with $this->init(). Writing the option directly and then
	// flushing meant the flush below regenerated rules from the OLD structure
	// and persisted them, and a populated stale ruleset does not self-heal
	// (#48). It happened to be masked by the unrelated flush that
	// check_theme_switched() runs after switch_theme(); this no longer depends
	// on that accident.
	if ( isset( $GLOBALS['wp_rewrite'] ) && is_object( $GLOBALS['wp_rewrite'] )
		&& method_exists( $GLOBALS['wp_rewrite'], 'set_permalink_structure' ) ) {
		$GLOBALS['wp_rewrite']->set_permalink_structure( '/%postname%/' );
	} else {
		update_option( 'permalink_structure', '/%postname%/' );
	}

	update_option( 'contai_flush_rewrite', true );

	// Actually flush. Writing the permalink_structure option does NOT
	// regenerate anything on its own, and the 'contai_flush_rewrite' flag above
	// was written for a consumer that does not exist: its only other occurrence
	// in the repo is uninstall.php's delete list, so nothing has ever read it
	// (#48). flush_rewrite_rules() likewise appeared nowhere in the plugin.
	//
	// What that costs, read from WordPress core rather than assumed:
	// WP_Rewrite::wp_rewrite_rules() (class-wp-rewrite.php:1493-1500) does
	// self-heal the 'rewrite_rules' OPTION, but only when it is empty — a
	// populated ruleset left over from the previous structure survives intact.
	// And in neither case is .htaccess touched: save_mod_rewrite_rules() is
	// reached only through a HARD flush_rules() (class-wp-rewrite.php:1899-1903).
	// On Apache, without the WordPress block in .htaccess every pretty permalink
	// 404s — the generated posts and the category archives the main menu links
	// to — which is the reported "generated site's navigation does not work",
	// arrived at silently.
	contai_flush_rewrite_rules_hard();

	// Enable comments on new posts by default
	update_option( 'default_comment_status', 'open' );

	contai_delete_sample_content();
}

/**
 * Flush rewrite rules hard, including .htaccess, from any request context.
 *
 * save_mod_rewrite_rules() lives in wp-admin/includes/misc.php, and
 * WP_Rewrite::flush_rules() guards its call with function_exists(). The wizard
 * runs its steps through the job queue (wp-cron), where wp-admin is not loaded,
 * so a plain flush_rewrite_rules( true ) there would silently degrade to a soft
 * flush and leave .htaccess untouched — the same class of silent no-op this
 * fixes. Loading the file first is what makes the "hard" part real.
 *
 * @return void
 */
function contai_flush_rewrite_rules_hard(): void {
	if ( ! function_exists( 'save_mod_rewrite_rules' ) && defined( 'ABSPATH' ) ) {
		$misc = ABSPATH . 'wp-admin/includes/misc.php';
		if ( file_exists( $misc ) ) {
			require_once $misc;
		}
	}

	if ( ! function_exists( 'flush_rewrite_rules' ) ) {
		return;
	}

	flush_rewrite_rules( true );

	// The flag now describes reality instead of a permanent "todo".
	update_option( 'contai_flush_rewrite', false );
}

/**
 * Configure site metadata including title and tagline
 *
 * Sets up core site metadata:
 * - Site Title: Set to the website host/domain name (without protocol)
 * - Tagline: AI-generated short description based on site topic and language
 *
 * This function extracts the host from the site URL and generates a compelling
 * tagline using OpenAI based on the configured site topic and language settings.
 *
 * @return void
 */
function contai_configure_site_metadata() {
	$site_url = home_url();
	$host = wp_parse_url( $site_url, PHP_URL_HOST );

	if ( $host ) {
		update_option( 'blogname', $host );
	}

	// Fetch the AI-generated tagline from the API
	$website_provider = new ContaiWebsiteProvider();
	$response         = $website_provider->getWebsiteFromApi();

	if ( $response && $response->isSuccess() ) {
		$data    = $response->getData();
		$tagline = $data['site_description'] ?? '';

		if ( ! empty( $tagline ) ) {
			update_option( 'blogdescription', sanitize_text_field( $tagline ) );
		}
	}
}

/**
 * Create a footer navigation menu containing legal pages.
 *
 * Finds all published pages created by the legal page generator
 * (identified by _contai_legal_source meta) and adds them to a
 * "Footer" menu assigned to the theme's footer menu location.
 *
 * @return void
 */
function contai_create_footer_menu_with_legal_pages(): void {
	$menu_name = 'Footer';
	$menu      = wp_get_nav_menu_object( $menu_name );

	if ( $menu ) {
		$menu_id = $menu->term_id;
	} else {
		$menu_id = wp_create_nav_menu( $menu_name );
		if ( is_wp_error( $menu_id ) ) {
			// The caller appends 'Footer menu with legal pages created' as soon
			// as this returns without throwing, so a debug-gated contai_log()
			// here meant the wizard claimed a footer menu it had failed to
			// create — with nothing to read anywhere (#48).
			contai_record_site_warning(
				'footer menu',
				'failed to create the menu: ' . $menu_id->get_error_message()
			);
			return;
		}
	}

	// Find legal pages by meta
    // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
	$legal_pages = get_posts(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'meta_key'    => '_contai_legal_source',
			'meta_value'  => 'contai_api',
			'numberposts' => 20,
			'orderby'     => 'title',
			'order'       => 'ASC',
		)
	);
    // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

	if ( empty( $legal_pages ) ) {
		return;
	}

	// Get existing menu items to avoid duplicates
	$existing_items = wp_get_nav_menu_items( $menu_id );
	$existing_ids   = array();
	if ( $existing_items ) {
		foreach ( $existing_items as $item ) {
			if ( $item->type === 'post_type' && $item->object === 'page' ) {
				$existing_ids[] = (int) $item->object_id;
			}
		}
	}

	$position = count( $existing_ids ) + 1;
	foreach ( $legal_pages as $page ) {
		if ( in_array( $page->ID, $existing_ids, true ) ) {
			continue;
		}

		wp_update_nav_menu_item( $menu_id, 0, array(
			'menu-item-title'     => $page->post_title,
			'menu-item-object'    => 'page',
			'menu-item-object-id' => $page->ID,
			'menu-item-type'      => 'post_type',
			'menu-item-status'    => 'publish',
			'menu-item-position'  => $position,
		) );
		$position++;
	}

	// Assign to footer menu location
	$theme = get_option( 'contai_wordpress_theme', 'astra' );

	// Theme-specific footer location overrides.
	//
	// Verified against each theme's register_nav_menus() call (#48). Three
	// themes are ABSENT on purpose: generatepress 3.6.1 registers only
	// 'primary' (functions.php:56-60), sydney 2.69 only 'primary'/'mobile'
	// (functions.php:56-65) and colormag 4.2.1 only 'primary'/'menu-secondary'
	// (inc/core/class-colormag-after-setup-theme.php:315-320). None of them has
	// a footer menu location in the free build, so inventing a plausible slug
	// here would recreate exactly the silent no-op this issue is about. With no
	// map entry the pattern fallback runs and, failing that, logs the
	// diagnostic naming the locations the theme really has.
	$theme_footer_map = array(
		'astra'         => 'footer_menu',
		'neve'          => 'footer',
		'oceanwp'       => 'footer_menu',
		'blocksy'       => 'footer',
		// Kadence's constant is FOOTER_NAV_MENU_SLUG = 'footer'
		// (kadence 1.5.1: inc/components/nav_menus/component.php:35,86);
		// 'footer_navigation' appears nowhere in the theme.
		'kadence'       => 'footer',
		// Newsmatic's footer nav is 'menu-3', guarded by has_nav_menu('menu-3')
		// (newsmatic 1.5.0: inc/hooks/footer-hooks.php:68-71), so the dead
		// 'footer-menu' entry rendered no footer navigation whatsoever.
		'newsmatic'     => 'menu-3',
	);

	$target     = $theme_footer_map[ $theme ] ?? null;
	$registered = get_registered_nav_menus();

	// The wizard reaches this function in the same request as switch_theme(),
	// so the registry above can still describe the theme we just left. Judging
	// the incoming theme's location against it is wrong in both directions —
	// see contai_nav_registry_is_stale() (#48).
	$stale = contai_nav_registry_is_stale();

	// Only short-circuit on the static map when the active theme actually
	// registers that location. Assigning an unregistered location is silently
	// dropped by WordPress, and returning here used to make the pattern-match
	// fallback and the diagnostic warning below unreachable for every mapped
	// theme — the silent failure behind "footer has no legal links" (#48).
	if ( contai_nav_location_is_usable( $target, $registered, $stale ) ) {
		// Claims the assignment as well, so core's post-switch remapping cannot
		// silently hand the location back to the previous theme (#48) — see
		// contai_assign_nav_menu_location().
		contai_assign_nav_menu_location( $target, $menu_id );
		return;
	}

	// Fallback: pattern-match footer location from registered nav menus.
	$matched = contai_match_footer_nav_location( $registered, $stale );

	if ( null !== $matched ) {
		contai_assign_nav_menu_location( $matched, $menu_id );
		error_log( "[ContAI] Footer menu assigned to '{$matched}' via pattern match for theme '{$theme}'" );
		return;
	}

	// Durable, not debug-gated: an unbound footer menu is invisible on the site
	// and left no trace anywhere before this (#48).
	contai_record_site_warning(
		'footer nav location',
		sprintf(
			"no footer location found for theme '%s'%s. Registered menus: %s",
			$theme,
			$stale ? ' (registry still described the previous theme)' : '',
			implode( ', ', array_keys( is_array( $registered ) ? $registered : array() ) )
		)
	);
}

/**
 * Fetch a generated profile from the 1Platform API.
 *
 * Calls POST /users/generations/profile and caches the result
 * in a WordPress transient keyed by owner + context + lang.
 *
 * @param string $owner   Site owner name (sanitized before sending).
 * @param string $context Website niche/theme (sanitized before sending).
 * @param string $lang    Language code: 'en' or 'es'.
 * @param int    $ttl     Cache TTL in seconds (default 6 hours).
 * @return array|null     Profile data array on success, null on failure.
 *
 * Curl example for Postman import:
 *   curl -X POST "https://api.1platform.pro/api/v1/users/generations/profile" \
 *     -H "Content-Type: application/json" \
 *     -H "Authorization: Bearer <APP_ACCESS_TOKEN>" \
 *     -H "x-user-token: <USER_ACCESS_TOKEN>" \
 *     -d '{"owner":"John Doe","context":"technology blog","lang":"en"}'
 *
 * Expected response:
 *   {
 *     "success": true,
 *     "data": {
 *       "fullname": "John Doe",
 *       "gender": "male",
 *       "bio": "<p>I'm <strong>John Doe</strong>, a technology enthusiast...</p>",
 *       "rrss": "<ul><li><a href=\"https://linkedin.com/in/johndoe\">LinkedIn</a></li></ul>",
 *       "profile_image_url": "https://example.com/images/profile.jpg"
 *     }
 *   }
 */
function contai_fetch_generated_profile_from_api( string $owner, string $context, string $lang, int $ttl = 21600 ): ?array {
	$safe_owner   = sanitize_text_field( $owner );
	$safe_context = sanitize_text_field( $context );
	$safe_lang    = sanitize_text_field( $lang );

	$cache_key = 'contai_profile_' . md5( $safe_owner . $safe_context . $safe_lang );
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) && ! empty( $cached ) ) {
		return $cached;
	}

	$client   = ContaiOnePlatformClient::create();
	$response = $client->post(
		ContaiOnePlatformEndpoints::GENERATIONS_PROFILE,
		array(
			'owner'   => $safe_owner,
			'context' => $safe_context,
			'lang'    => $safe_lang,
		)
	);

	if ( ! $response->isSuccess() ) {
		contai_log( 'contai_fetch_generated_profile_from_api: API error — ' . ( $response->getMessage() ?? 'unknown' ) );
		return null;
	}

	$data = $response->getData();

	$required_fields = array( 'fullname', 'gender', 'bio', 'rrss', 'profile_image_url' );
	foreach ( $required_fields as $field ) {
		if ( ! isset( $data[ $field ] ) || $data[ $field ] === '' ) {
			contai_log( 'contai_fetch_generated_profile_from_api: Missing field — ' . $field );
			return null;
		}
	}

	set_transient( $cache_key, $data, $ttl );

	return $data;
}

/**
 * Download a profile image and upload it to the WordPress media library.
 *
 * Avoids duplicate uploads by checking for an existing attachment with matching
 * _contai_source_image_url post meta.
 *
 * @param string $profile_image_url Remote image URL to download.
 * @param string $fullname          Person's full name (used for filename).
 * @return string|false             Local attachment URL on success, false on failure.
 */
function contai_sideload_profile_image( string $profile_image_url, string $fullname ) {
	if ( empty( $profile_image_url ) || ! filter_var( $profile_image_url, FILTER_VALIDATE_URL ) ) {
		return false;
	}

    // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Meta query needed to find existing attachment by source URL.
	$existing = get_posts(
		array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'meta_key'    => '_contai_source_image_url',
			'meta_value'  => $profile_image_url,
			'numberposts' => 1,
			'fields'      => 'ids',
		)
	);
    // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

	if ( ! empty( $existing ) ) {
		$url = wp_get_attachment_url( $existing[0] );
		if ( $url ) {
			return $url;
		}
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$tmp = download_url( $profile_image_url );

	if ( is_wp_error( $tmp ) ) {
		contai_log( 'contai_sideload_profile_image: download failed — ' . $tmp->get_error_message() );
		return false;
	}

	$file_array = array(
		'name'     => sanitize_file_name( $fullname . '-profile.jpg' ),
		'tmp_name' => $tmp,
	);

	$attachment_id = media_handle_sideload( $file_array, 0 );

	if ( is_wp_error( $attachment_id ) ) {
		wp_delete_file( $tmp );
		contai_log( 'contai_sideload_profile_image: sideload failed — ' . $attachment_id->get_error_message() );
		return false;
	}

	update_post_meta( $attachment_id, '_contai_source_image_url', $profile_image_url );

	return wp_get_attachment_url( $attachment_id );
}

/**
 * Add default widgets to sidebar
 *
 * Generates and configures default widgets for the primary sidebar including:
 * - About Me profile card (via WPContentAI API, with legacy fallback)
 * - Search widget for site-wide search
 * - Recent Comments widget showing latest comments
 * - Recent Posts widget showing latest posts with dates
 *
 * The About Me card first attempts the WPContentAI profile generation API.
 * If the API call fails (network error, invalid response, missing data),
 * it falls back to the legacy flow (OpenAI + ValueSerp + Pexels).
 *
 * All widget titles are automatically translated based on site language setting.
 *
 * @return void
 */
function contai_add_sidebar_widgets() {
	$lang  = get_option( 'contai_site_language', 'spanish' );
	$theme = get_option( 'contai_site_topic', get_option( 'contai_site_theme', 'blog' ) );

	// Clear cached profile so re-execution fetches a fresh one (#55)
	$legal_info_pre = contai_get_legal_info();
	$owner_pre      = sanitize_text_field( $legal_info_pre['owner'] ?? '' );
	$lang_code_pre  = ( $lang === 'english' ? 'en' : 'es' );
	$cache_key      = 'contai_profile_' . md5( $owner_pre . sanitize_text_field( $theme ) . sanitize_text_field( $lang_code_pre ) );
	delete_transient( $cache_key );

	$labels = array(
		'spanish' => array(
			'search'          => 'Búsqueda',
			'recent_comments' => 'Últimos comentarios',
			'recent_posts'    => 'Últimas entradas',
		),
		'english' => array(
			'search'          => 'Search',
			'recent_comments' => 'Recent Comments',
			'recent_posts'    => 'Recent Posts',
		),
	);

	$text = $labels[ $lang ] ?? $labels['spanish'];

	$sidebars_widgets = get_option( 'sidebars_widgets', array() );
	$sidebar_id       = contai_get_primary_sidebar_id();

	// Read the ids this plugin assigned on a previous run BEFORE clearing the
	// list, so the allocation below can re-use them (#48).
	$previous_sidebar = isset( $sidebars_widgets[ $sidebar_id ] ) && is_array( $sidebars_widgets[ $sidebar_id ] )
		? $sidebars_widgets[ $sidebar_id ]
		: array();

	// Collected here and merged with $previous_sidebar at the end. Assigning
	// array() to the sidebar at this point — which is what this did — dropped
	// every widget the site owner had placed there (#48).
	$wizard_widget_ids = array();

	// Read-merge, never rebuild. Each of these options holds EVERY instance of
	// that widget type across EVERY sidebar, so overwriting it with a freshly
	// built one-instance array silently destroyed the settings of widgets other
	// sidebars still reference (#48).
	$widget_search          = get_option( 'widget_search', array() );
	$widget_recent_comments = get_option( 'widget_recent-comments', array() );
	$widget_recent_posts    = get_option( 'widget_recent-posts', array() );
	$widget_block           = get_option( 'widget_block', array() );

	$widget_search          = is_array( $widget_search ) ? $widget_search : array();
	$widget_recent_comments = is_array( $widget_recent_comments ) ? $widget_recent_comments : array();
	$widget_recent_posts    = is_array( $widget_recent_posts ) ? $widget_recent_posts : array();
	$widget_block           = is_array( $widget_block ) ? $widget_block : array();

	// Hardcoding 1 collided with whatever already occupied instance 1. The
	// fingerprints are what keep re-use from adopting a widget the site owner
	// (or WordPress itself) put in this sidebar — 'block-2' on a stock install
	// is core's Search block, not ours (#48).
	$search_id   = contai_pick_widget_instance_id( $widget_search, $previous_sidebar, 'search', $text['search'] );
	$comments_id = contai_pick_widget_instance_id( $widget_recent_comments, $previous_sidebar, 'recent-comments', $text['recent_comments'] );
	$posts_id    = contai_pick_widget_instance_id( $widget_recent_posts, $previous_sidebar, 'recent-posts', $text['recent_posts'] );
	$block_id    = contai_pick_widget_instance_id( $widget_block, $previous_sidebar, 'block', CONTAI_ABOUT_ME_WIDGET_CLASS );

	$legal_info = contai_get_legal_info();
	$owner      = $legal_info['owner'] ?? '';

	$website_provider = new ContaiWebsiteProvider();
	$lang_code        = $website_provider->getLanguageCode() ?? ( $lang === 'english' ? 'en' : 'es' );

	$profile = contai_fetch_generated_profile_from_api( $owner, $theme, $lang_code );

	if ( $profile ) {
		$fullname  = sanitize_text_field( $profile['fullname'] );
		$bio_safe  = wp_kses_post( $profile['bio'] );
		$rrss_safe = wp_kses_post( $profile['rrss'] );
		$image_url = contai_sideload_profile_image( $profile['profile_image_url'], $fullname );

		if ( ! $image_url ) {
			$image_url = esc_url( $profile['profile_image_url'] );
		}

		$about_me_title = $lang === 'english' ? 'About Me' : 'Sobre mí';

		$about_me_html = '
    <div class="' . CONTAI_ABOUT_ME_WIDGET_CLASS . '" style="width: 100%; max-width: 320px; padding: 20px; border: 1px solid #ccc; border-radius: 10px; font-family: Arial, sans-serif; background-color: #f0f4fa; box-sizing: border-box; margin: 0 auto;">
        <img src="' . esc_url( $image_url ) . '" alt="' . esc_attr( $fullname ) . '" style="width: 100%; height: auto; border-radius: 10px; margin-bottom: 15px; display: block;">
        <h2 style="margin-top: 0; font-size: 18px; margin-bottom: 10px;">' . esc_html( $about_me_title ) . '</h2>
        <p style="margin: 10px 0; font-size: 14px; line-height: 1.6;">' . $bio_safe . '</p>
        ' . $rrss_safe . '
    </div>';

		$wizard_widget_ids[]       = "block-$block_id";
		$widget_block[ $block_id ] = array( 'content' => $about_me_html );
	} else {
		// Durable, not debug-gated: contai_log() writes nothing without
		// WP_DEBUG, and the step still reports "Widgets generated", so one of
		// the four widgets went missing with no trace anywhere (#48).
		contai_record_site_warning(
			'about me widget',
			'profile generation returned nothing; the About Me widget was skipped.'
		);
	}

	$wizard_widget_ids[] = "search-$search_id";
	$wizard_widget_ids[] = "recent-comments-$comments_id";
	$wizard_widget_ids[] = "recent-posts-$posts_id";

	// Wizard widgets first, everything the owner already had after it. Re-runs
	// collapse instead of duplicating because contai_pick_widget_instance_id()
	// hands back the ids the previous run wrote.
	$sidebars_widgets[ $sidebar_id ] = contai_merge_sidebar_widget_ids( $wizard_widget_ids, $previous_sidebar );

	$widget_search[ $search_id ] = array(
		'title' => $text['search'],
	);

	$widget_recent_comments[ $comments_id ] = array(
		'title'  => $text['recent_comments'],
		'number' => 5,
	);

	$widget_recent_posts[ $posts_id ] = array(
		'title'     => $text['recent_posts'],
		'number'    => 5,
		'show_date' => true,
	);

	$widget_search['_multiwidget']          = 1;
	$widget_recent_comments['_multiwidget'] = 1;
	$widget_recent_posts['_multiwidget']    = 1;
	$widget_block['_multiwidget']           = 1;

	// These are standard WordPress core widget option names (widget_block, widget_search, etc.).
	// WordPress requires these exact names to configure sidebar widgets programmatically.
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedKeyFound -- Core WordPress widget option names.
	update_option( 'widget_block', $widget_block );
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedKeyFound -- Core WordPress widget option name.
	update_option( 'widget_search', $widget_search );
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedKeyFound -- Core WordPress widget option name.
	update_option( 'widget_recent-comments', $widget_recent_comments );
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedKeyFound -- Core WordPress widget option name.
	update_option( 'widget_recent-posts', $widget_recent_posts );
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedKeyFound -- Core WordPress widget option name.
	update_option( 'sidebars_widgets', $sidebars_widgets );
}
