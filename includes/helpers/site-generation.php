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
 * Get the primary sidebar ID for the active theme.
 *
 * Different themes register sidebars with different IDs. This function
 * detects the correct sidebar by checking common naming conventions.
 *
 * @return string The primary sidebar ID
 */
function contai_get_primary_sidebar_id(): string {
	global $wp_registered_sidebars;

	if ( empty( $wp_registered_sidebars ) ) {
		return 'sidebar-1';
	}

	$priority = array( 'sidebar-1', 'sidebar', 'sidebar-primary', 'primary-sidebar', 'primary-widget-area' );
	foreach ( $priority as $id ) {
		if ( isset( $wp_registered_sidebars[ $id ] ) ) {
			return $id;
		}
	}

	// Fallback: first registered sidebar
	$keys = array_keys( $wp_registered_sidebars );
	return $keys[0] ?? 'sidebar-1';
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
			break;

		case 'oceanwp':
			set_theme_mod( 'ocean_blog_layout', 'right-sidebar' );
			break;

		case 'generatepress':
			set_theme_mod( 'content_layout_setting', 'content-sidebar' );
			break;

		case 'colormag':
			set_theme_mod( 'colormag_site_layout', 'right-sidebar' );
			break;

		// astra, neve, blocksy, kadence, sydney: sensible defaults out of the box
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
				'slug' => $theme,
				'fields' => array( 'sections' => false ),
			)
		);

		if ( ! is_wp_error( $api ) ) {
			$upgrader = new Theme_Upgrader( new WP_Ajax_Upgrader_Skin() );
			$upgrader->install( $api->download_link );
		}
	}

	switch_theme( $theme );
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
	echo '<div class="notice notice-success is-dismissible"><p>Widgets generated successfully.</p></div>';
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
		echo '<div class="notice notice-error"><p>Error: ' . esc_html( $result->get_error_message() ) . '</p></div>';
	} else {
		echo '<div class="notice notice-success is-dismissible"><p>Icon generated and assigned successfully. Attachment ID: ' . intval( $result ) . '.</p></div>';
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
	$lang    = get_option( 'contai_site_language', 'spanish' );
	$theme   = get_option( 'contai_site_theme', 'blog' );
	$site_url = get_site_url();
	$domain  = wp_parse_url( $site_url, PHP_URL_HOST );

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

	if ( ! $profile ) {
		contai_log( 'contai_add_sidebar_widgets: Profile generation failed, skipping About Me widget.' );
		return;
	}

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
	$sidebars_widgets[ $sidebar_id ][] = "search-$search_id";
	$sidebars_widgets[ $sidebar_id ][] = "recent-comments-$comments_id";
	$sidebars_widgets[ $sidebar_id ][] = "recent-posts-$posts_id";

	$widget_block[ $block_id ] = array( 'content' => $about_me_html );

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
