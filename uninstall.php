<?php
/**
 * Content AI Uninstall
 *
 * Fired when the plugin is deleted via the WordPress admin.
 * Cleans up all plugin data including custom tables, options, and transients.
 *
 * @package ContentAI
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Delete custom tables.
$content_ai_tables = array(
	$wpdb->prefix . 'contai_keywords',
	$wpdb->prefix . 'contai_jobs',
	$wpdb->prefix . 'contai_internal_links',
	$wpdb->prefix . 'contai_api_logs',
);

foreach ( $content_ai_tables as $content_ai_table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping plugin tables on uninstall; table names are hardcoded above.
	$wpdb->query( "DROP TABLE IF EXISTS {$content_ai_table}" );
}

// Delete plugin options.
$content_ai_options = array(
	// Legacy API keys (encrypted).
	'contai_api_key',
	'contai_openai_key',
	'contai_valueserp_key',
	'contai_pixabay_key',
	'contai_pexels_key',
	// Legacy site config.
	'contai_user_profile',
	'contai_user_website',
	'contai_site_theme',
	'contai_site_language',
	'contai_site_category',
	'contai_wordpress_theme',
	'contai_image_provider',
	// Legacy legal info.
	'contai_legal_owner',
	'contai_legal_email',
	'contai_legal_address',
	'contai_legal_activity',
	// Legacy ads and cookie settings.
	'contai_adsense_publishers',
	'contai_custom_head',
	'contai_cookie_notice_enabled',
	'contai_cookie_notice_text',
	// Auth tokens.
	'contai_app_access_token',
	'contai_app_token_expires_at',
	'contai_user_access_token',
	'contai_user_token_expires_at',
	'contai_app_token_error',
	'contai_user_token_error',
	// Site hardening.
	'contai_adsense_approved',
	'contai_disable_feeds',
	'contai_disable_author_pages',
	'contai_redirect_404',
	'contai_flush_rewrite',
	// Site config (new prefix).
	'contai_site_category',
	'contai_site_language',
	// Feature config.
	'contai_toc_config',
	'contai_publisuites_config',
	'contai_api_logging_enabled',
	'contai_logging_enabled',
	'contai_api_base_url',
	// Internal links individual settings.
	'contai_internal_links_enabled',
	'contai_internal_links_max_per_post',
	'contai_internal_links_max_per_keyword',
	'contai_internal_links_max_per_target',
	'contai_internal_links_batch_size',
	'contai_internal_links_excluded_tags',
	'contai_internal_links_case_insensitive',
	'contai_internal_links_word_boundaries',
	'contai_internal_links_same_category',
	'contai_internal_links_min_keyword_length',
	'contai_internal_links_distribute',
	// Analytics integration.
	'1platform_ga4_measurement_id',
	'1platform_website_id',
);

foreach ( $content_ai_options as $content_ai_option ) {
	delete_option( $content_ai_option );
}

// Delete dynamic batch options (contai_batch_*_total, contai_batch_*_started_at).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", 'contai\_batch\_%' ) );

// Delete transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_contai_%' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_timeout_contai_%' ) );

// Clear scheduled cron events.
wp_clear_scheduled_hook( 'contai_process_job_queue' );
wp_clear_scheduled_hook( 'contai_agent_actions_poll' );
