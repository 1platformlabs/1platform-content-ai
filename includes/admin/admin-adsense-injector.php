<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function contai_inject_adsense_snippets() {
	static $already_run = false;
	if ( $already_run ) {
		return;
	}
	$already_run = true;

	if ( contai_skip_referral() ) {
		return;
	}

	$publishers = array_filter( array_map( 'trim', explode( "\n", get_option( 'contai_adsense_publishers', '' ) ) ) );

	foreach ( $publishers as $pub ) {
		if ( preg_match( '/^pub\-\d+$/', $pub ) ) {
			$full_pub = 'ca-' . esc_attr( $pub );
			wp_enqueue_script(
				'google-adsense-' . $pub,
				'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . $full_pub,
				array(),
				'1.0.0',
				false
			);
			echo '<meta name="google-adsense-account" content="' . esc_attr( $full_pub ) . '">' . "\n";
		}
	}
}

function contai_mark_adsense_scripts_async( $tag, $handle ) {
	if ( strpos( $handle, 'google-adsense-' ) === 0 ) {
		return str_replace( ' src=', ' async crossorigin="anonymous" src=', $tag );
	}
	return $tag;
}
add_filter( 'script_loader_tag', 'contai_mark_adsense_scripts_async', 10, 2 );


function contai_skip_referral() {
	$ua = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) );

	if ( $ua === '' || ( preg_match( '/(bot|crawl|spider|slurp|fetch|crawler|preview|facebookexternalhit|pingdom)/i', $ua ) && ! preg_match( '/(googlebot|mediapartners-google)/i', $ua ) ) ) {
		return true;
	}

	$referer_host = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) ), PHP_URL_HOST );

	if (
		is_404() ||
		( is_home() && ! empty( $_COOKIE['eads'] ) ) ||
		( is_home() && ! empty( $_COOKIE['cpmredirect'] ) ) ||
		$referer_host === 'away.vk.com'
	) {
		return true;
	}

	if ( ( ! empty( $_COOKIE['ads'] ) || ! empty( $_COOKIE['adx'] ) ) &&
		$referer_host !== 'www.google.com'
	) {
		return true;
	}

	return false;
}

function contai_inject_custom_head() {
	if ( contai_skip_referral() ) {
		return;
	}

	$custom_head = get_option( 'contai_custom_head', '' );
	if ( ! empty( $custom_head ) ) {
		$allowed_tags = array_merge(
			wp_kses_allowed_html( 'post' ),
			array(
				'meta'   => array(
					'name' => true,
					'content' => true,
					'charset' => true,
					'http-equiv' => true,
					'property' => true,
				),
				'link'   => array(
					'rel' => true,
					'href' => true,
					'type' => true,
					'media' => true,
				),
				'style'  => array(
					'type' => true,
					'media' => true,
				),
				'script' => array(
					'src' => true,
					'async' => true,
					'defer' => true,
					'type' => true,
					'crossorigin' => true,
				),
			)
		);
		echo wp_kses( $custom_head, $allowed_tags ) . "\n";
	}
}

function contai_generate_adsense_ads() {
	global $wp_filesystem;

	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	WP_Filesystem();

	$publishers = array_filter( array_map( 'trim', explode( "\n", get_option( 'contai_adsense_publishers', '' ) ) ) );
	$ads_txt_path = ABSPATH . 'ads.txt';

	if ( empty( $publishers ) ) {
		if ( $wp_filesystem && $wp_filesystem->exists( $ads_txt_path ) ) {
			$wp_filesystem->delete( $ads_txt_path );
		}
		return;
	}

	$ads_lines = array();
	foreach ( $publishers as $pub ) {
		if ( preg_match( '/^pub\-(\d+)$/', $pub, $m ) ) {
			$ads_lines[] = "google.com, pub-{$m[1]}, DIRECT, f08c47fec0942fa0";
		}
	}

	if ( ! empty( $ads_lines ) && $wp_filesystem ) {
		$wp_filesystem->put_contents( $ads_txt_path, implode( "\n", $ads_lines ), FS_CHMOD_FILE );
	}
}

add_action( 'wp_head', 'contai_inject_adsense_snippets', 5 );
add_action( 'wp_head', 'contai_inject_custom_head', 20 );
