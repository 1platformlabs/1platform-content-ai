<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ContaiCookieNoticeHelper {

	public static function enqueue_assets(): void {
		$css_path = plugin_dir_path( __DIR__ ) . 'assets/css/cookie-notice.css';
		$js_path  = plugin_dir_path( __DIR__ ) . 'assets/js/cookie-notice.js';
		$css_url  = plugin_dir_url( __DIR__ ) . 'assets/css/cookie-notice.css';
		$js_url   = plugin_dir_url( __DIR__ ) . 'assets/js/cookie-notice.js';

		wp_enqueue_style(
			'contai-cookie-notice',
			$css_url,
			array(),
			file_exists( $css_path ) ? filemtime( $css_path ) : '1.0.0'
		);

		wp_enqueue_script(
			'contai-cookie-notice',
			$js_url,
			array(),
			file_exists( $js_path ) ? filemtime( $js_path ) : '1.0.0',
			true
		);
	}

	public static function render_cookie_notice(): void {
		$enabled = get_option( 'contai_cookie_notice_enabled', '1' );
		if ( $enabled !== '1' || isset( $_COOKIE['cookie_notice_accepted'] ) ) {
			return;
		}

		self::enqueue_assets();

		$language = get_option( 'contai_site_language', 'spanish' );
		$text = get_option( 'contai_cookie_notice_text' );
		$link = $language === 'english' ? '/privacy-policy/' : '/politica-de-privacidad/';
		$accept_label = $language === 'english' ? 'Accept' : 'Estoy de acuerdo';
		$reject_label = $language === 'english' ? 'Reject' : 'Rechazar';
		$privacy_label = $language === 'english' ? 'Privacy Policy' : 'Política de privacidad';
		?>

		<div id="cookie-notice" role="dialog" class="cookie-revoke-hidden cn-position-bottom cn-effect-slide cookie-notice-visible cn-animated" aria-label="Cookie Notice" style="background-color: rgba(0,0,0,1);">
			<div class="cookie-notice-container" style="color: #fff;">
				<span id="cn-notice-text" class="cn-text-container"><?php echo esc_html( $text ); ?></span>
				<span id="cn-notice-buttons" class="cn-buttons-container">
					<button id="cn-accept-cookie" data-cookie-set="accept" class="cn-set-cookie cn-button cn-button-custom button" aria-label="<?php echo esc_attr( $accept_label ); ?>"><?php echo esc_html( $accept_label ); ?></button>
					<button id="cn-refuse-cookie" data-cookie-set="refuse" class="cn-set-cookie cn-button cn-button-custom button" aria-label="<?php echo esc_attr( $reject_label ); ?>"><?php echo esc_html( $reject_label ); ?></button>
					<button onclick="window.open('<?php echo esc_url( $link ); ?>', '_blank')" class="cn-button cn-button-custom button" aria-label="<?php echo esc_attr( $privacy_label ); ?>"><?php echo esc_html( $privacy_label ); ?></button>
				</span>
				<span id="cn-close-notice" data-cookie-set="accept" class="cn-close-icon" title="<?php echo esc_attr( $reject_label ); ?>"></span>
			</div>
		</div>
		<?php
	}
}

add_action(
	'init',
	function () {
		register_nav_menu( 'contai-footer-bottom', 'Content AI Footer Bottom' );
	}
);

add_action( 'wp_footer', array( ContaiCookieNoticeHelper::class, 'render_cookie_notice' ) );
