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
require_once __DIR__ . '/../services/api/OnePlatformClient.php';
require_once __DIR__ . '/../services/api/OnePlatformEndpoints.php';
require_once __DIR__ . '/../providers/WebsiteProvider.php';

/**
 * Static mapping of sidebar IDs per theme.
 *
 * Used in cron/async context where $wp_registered_sidebars may be empty
 * because the theme's widgets_init hook has not fired.
 */
define( 'CONTAI_THEME_SIDEBAR_MAP', array(
	'astra'          => 'sidebar-1',
	'generatepress'  => 'sidebar-1',
	'neve'           => 'sidebar-1',
	'blocksy'        => 'sidebar-1',
	'kadence'        => 'sidebar-1',
	'sydney'         => 'sidebar-1',
	'oceanwp'        => 'sidebar',
	'newsmatic'      => 'sidebar-1',
	'colormag'       => 'sidebar-right',
) );

/**
 * Static mapping of primary nav menu locations per theme.
 *
 * Used in cron/async context where get_registered_nav_menus() may return empty.
 */
define( 'CONTAI_THEME_NAV_LOCATION_MAP', array(
	'astra'          => 'primary',
	'generatepress'  => 'primary',
	'neve'           => 'primary',
	'blocksy'        => 'header-menu-1',
	'kadence'        => 'primary',
	'sydney'         => 'primary',
	'oceanwp'        => 'main_menu',
	'newsmatic'      => 'menu-1',
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

	// Try runtime detection as fallback
	global $wp_registered_sidebars;
	if ( ! empty( $wp_registered_sidebars ) ) {
		$priority = array( 'sidebar-1', 'sidebar', 'sidebar-primary', 'primary-sidebar', 'primary-widget-area' );
		foreach ( $priority as $id ) {
			if ( isset( $wp_registered_sidebars[ $id ] ) ) {
				return $id;
			}
		}
		$keys = array_keys( $wp_registered_sidebars );
		return $keys[0] ?? 'sidebar-1';
	}

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
	update_option( 'posts_per_page', 10 );

	switch ( $theme ) {
		case 'newsmatic':
			if ( function_exists( 'contai_set_newsmatic_reading_defaults' ) ) {
				contai_set_newsmatic_reading_defaults();
			}
			set_theme_mod( 'newsmatic_breadcrumb_option', true );
			break;

		case 'oceanwp':
			set_theme_mod( 'ocean_blog_layout', 'right-sidebar' );
			set_theme_mod( 'ocean_breadcrumbs', true );
			break;

		case 'generatepress':
			set_theme_mod( 'content_layout_setting', 'content-sidebar' );
			break;

		case 'colormag':
			set_theme_mod( 'colormag_site_layout', 'right-sidebar' );
			set_theme_mod( 'colormag_breadcrumb_display', true );
			break;

		case 'astra':
			// Force right sidebar layout site-wide
			set_theme_mod( 'site-sidebar-layout', 'right-sidebar' );
			set_theme_mod( 'single-post-sidebar-layout', 'right-sidebar' );
			set_theme_mod( 'archive-post-sidebar-layout', 'right-sidebar' );
			// Enable breadcrumbs on single posts and archives
			set_theme_mod( 'ast-breadcrumbs-position', 'astra_entry_top' );
			set_theme_mod( 'ast-breadcrumbs-separator', '»' );
			break;

		case 'neve':
			set_theme_mod( 'neve_default_sidebar_layout', 'right' );
			set_theme_mod( 'neve_single_post_sidebar_layout', 'right' );
			set_theme_mod( 'neve_breadcrumbs', true );
			break;

		case 'blocksy':
			set_theme_mod( 'blog_has_sidebar', 'right' );
			set_theme_mod( 'single_has_sidebar', 'right' );
			set_theme_mod( 'breadcrumb_visibility', 'yes' );
			break;

		case 'kadence':
			set_theme_mod( 'post_layout', 'right' );
			set_theme_mod( 'archive_layout', 'right' );
			set_theme_mod( 'breadcrumb_enable', true );
			break;

		case 'sydney':
			set_theme_mod( 'sidebar_position', 'sidebar-right' );
			set_theme_mod( 'enable_breadcrumbs', 1 );
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
	update_option( 'permalink_structure', '/%postname%/' );
	update_option( 'contai_flush_rewrite', true );

	// Enable comments on new posts by default
	update_option( 'default_comment_status', 'open' );

	contai_delete_sample_content();
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
			contai_log( 'contai_create_footer_menu_with_legal_pages: failed to create menu — ' . $menu_id->get_error_message() );
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
	$footer_locations = array( 'footer', 'footer-menu', 'footer_menu', 'footer-nav', 'footer_navigation' );
	$theme            = get_option( 'contai_wordpress_theme', 'astra' );

	// Theme-specific footer location overrides
	$theme_footer_map = array(
		'astra'         => 'footer_menu',
		'generatepress' => 'secondary',
		'neve'          => 'footer',
		'oceanwp'       => 'footer_menu',
		'blocksy'       => 'footer',
		'kadence'       => 'footer_navigation',
		'sydney'        => 'footer',
		'newsmatic'     => 'footer-menu',
		'colormag'      => 'footer-menu',
	);

	$locations     = get_nav_menu_locations();
	$target        = $theme_footer_map[ $theme ] ?? null;

	if ( $target ) {
		$locations[ $target ] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );
		return;
	}

	// Fallback: try runtime-registered footer locations
	$registered = get_registered_nav_menus();
	foreach ( $footer_locations as $loc ) {
		if ( isset( $registered[ $loc ] ) ) {
			$locations[ $loc ] = $menu_id;
			set_theme_mod( 'nav_menu_locations', $locations );
			return;
		}
	}
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
	$sidebars_widgets[ $sidebar_id ] = array();

	$widget_search          = array( '_multiwidget' => 1 );
	$widget_recent_comments = array( '_multiwidget' => 1 );
	$widget_recent_posts    = array( '_multiwidget' => 1 );
	$widget_block           = array( '_multiwidget' => 1 );

	$search_id   = 1;
	$comments_id = 1;
	$posts_id    = 1;
	$block_id    = 1;

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
    <div class="contai-about-me-widget" style="width: 100%; max-width: 320px; padding: 20px; border: 1px solid #ccc; border-radius: 10px; font-family: Arial, sans-serif; background-color: #f0f4fa; box-sizing: border-box; margin: 0 auto;">
        <img src="' . esc_url( $image_url ) . '" alt="' . esc_attr( $fullname ) . '" style="width: 100%; height: auto; border-radius: 10px; margin-bottom: 15px; display: block;">
        <h2 style="margin-top: 0; font-size: 18px; margin-bottom: 10px;">' . esc_html( $about_me_title ) . '</h2>
        <p style="margin: 10px 0; font-size: 14px; line-height: 1.6;">' . $bio_safe . '</p>
        ' . $rrss_safe . '
    </div>';

		$sidebars_widgets[ $sidebar_id ][] = "block-$block_id";
		$widget_block[ $block_id ] = array( 'content' => $about_me_html );
	} else {
		contai_log( 'contai_add_sidebar_widgets: Profile generation failed, skipping About Me widget.' );
	}

	$sidebars_widgets[ $sidebar_id ][] = "search-$search_id";
	$sidebars_widgets[ $sidebar_id ][] = "recent-comments-$comments_id";
	$sidebars_widgets[ $sidebar_id ][] = "recent-posts-$posts_id";

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
