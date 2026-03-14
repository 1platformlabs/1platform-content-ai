<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function contai_disable_feeds() {
	if ( get_option( 'contai_disable_feeds', '0' ) !== '1' ) {
		return;
	}

	add_action( 'do_feed_rdf', 'contai_feed_disabled_message', 1 );
	add_action( 'do_feed_rss', 'contai_feed_disabled_message', 1 );
	add_action( 'do_feed_rss2', 'contai_feed_disabled_message', 1 );
	add_action( 'do_feed_atom', 'contai_feed_disabled_message', 1 );
	add_action( 'do_feed_rss2_comments', 'contai_feed_disabled_message', 1 );
	add_action( 'do_feed_atom_comments', 'contai_feed_disabled_message', 1 );
	remove_action( 'wp_head', 'feed_links_extra', 3 );
	remove_action( 'wp_head', 'feed_links', 2 );
}

function contai_feed_disabled_message() {
	wp_die(
		sprintf(
			/* translators: %1$s: opening link tag, %2$s: closing link tag */
			esc_html__( 'No feed available, please visit our %1$shomepage%2$s!', '1platform-content-ai' ),
			' <a href="' . esc_url( home_url( '/' ) ) . '">',
			'</a>'
		)
	);
}

function contai_disable_author_pages() {
	if ( get_option( 'contai_disable_author_pages', '0' ) !== '1' ) {
		return;
	}

	add_action(
		'template_redirect',
		function () {
			if ( is_author() ) {
				global $wp_query;
				$wp_query->set_404();
				status_header( 404 );
				nocache_headers();
			}
		}
	);

	add_filter( 'author_link', '__return_empty_string', 1000 );
	add_filter( 'the_author_posts_link', 'get_the_author', 1000, 0 );

	add_filter(
		'wp_sitemaps_add_provider',
		function ( $provider, $name ) {
			if ( 'users' === $name ) {
				return false;
			}
			return $provider;
		},
		10,
		2
	);

	add_filter(
		'user_row_actions',
		function ( $actions, $user ) {
			unset( $actions['view'] );
			unset( $actions['posts'] );
			return $actions;
		},
		10,
		2
	);
}

function contai_redirect_404_to_home() {
	if ( get_option( 'contai_redirect_404', '0' ) !== '1' ) {
		return;
	}

	add_action(
		'template_redirect',
		function () {
			if ( is_404() ) {
				wp_safe_redirect( home_url(), 301 );
				exit;
			}
		}
	);
}

add_action( 'init', 'contai_disable_feeds' );
add_action( 'init', 'contai_disable_author_pages' );
add_action( 'init', 'contai_redirect_404_to_home' );

function contai_enqueue_frontend_styles() {
	if ( ! is_active_widget( false, false, 'block', true ) ) {
		return;
	}

	$css_path = plugin_dir_path( __DIR__ ) . 'admin/content-generator/assets/css/about-me-widget.css';
	$css_url  = plugin_dir_url( __DIR__ ) . 'admin/content-generator/assets/css/about-me-widget.css';

	if ( file_exists( $css_path ) ) {
		wp_enqueue_style(
			'contai-about-me-widget',
			$css_url,
			array(),
			filemtime( $css_path )
		);
	}
}
add_action( 'wp_enqueue_scripts', 'contai_enqueue_frontend_styles' );
