<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/content-generator/components/layout.php';
require_once __DIR__ . '/content-generator/panels/keyword-extractor.php';
require_once __DIR__ . '/content-generator/panels/post-generator.php';
require_once __DIR__ . '/content-generator/panels/keywords-list.php';
require_once __DIR__ . '/content-generator/panels/post-maintenance.php';
require_once __DIR__ . '/content-generator/panels/generate-comments.php';
require_once __DIR__ . '/content-generator/helpers/legal-pages-helper.php';
require_once __DIR__ . '/content-generator/helpers/cookie-notice-helper.php';
require_once __DIR__ . '/content-generator/helpers/legacy-functions.php';
require_once __DIR__ . '/content-generator/panels/legal-pages.php';
require_once __DIR__ . '/content-generator/handlers/PostGenerationQueueHandler.php';
require_once __DIR__ . '/content-generator/handlers/KeywordExtractionHandler.php';

require_once __DIR__ . '/../database/repositories/KeywordRepository.php';

function contai_handle_post_generation_queue_submission() {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Page routing check; nonce verified in handler.
	if ( ! isset( $_GET['page'] ) || sanitize_key( wp_unslash( $_GET['page'] ) ) !== 'contai-content-generator' ) {
		return;
	}

	if ( ! isset( $_GET['section'] ) || sanitize_key( wp_unslash( $_GET['section'] ) ) !== 'post-generator' ) {
		return;
	}
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

	$handler = new ContaiPostGenerationQueueHandler();
	$handler->handleRequest();
}
add_action( 'admin_init', 'contai_handle_post_generation_queue_submission' );

function contai_handle_keyword_extraction_submission() {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Page routing check; nonce verified in handler.
	if ( ! isset( $_GET['page'] ) || sanitize_key( wp_unslash( $_GET['page'] ) ) !== 'contai-content-generator' ) {
		return;
	}

	if ( ! isset( $_GET['section'] ) || sanitize_key( wp_unslash( $_GET['section'] ) ) !== 'keyword-extractor' ) {
		return;
	}
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

	$handler = new ContaiKeywordExtractionHandler();
	$handler->handleRequest();
}
add_action( 'admin_init', 'contai_handle_keyword_extraction_submission' );

add_action( 'wp_ajax_contai_save_keywords', 'contai_ajax_save_keywords' );

function contai_ajax_save_keywords() {
	if ( ! check_ajax_referer( 'contai_save_keywords_nonce', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		return;
	}

	$keywords_json = sanitize_text_field( wp_unslash( $_POST['keywords'] ?? '' ) );
	$url = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );

	if ( empty( $keywords_json ) || empty( $url ) ) {
		wp_send_json_error( array( 'message' => 'Missing required data' ) );
		return;
	}

	$keywords_data = json_decode( stripslashes( $keywords_json ), true );

	if ( ! is_array( $keywords_data ) ) {
		wp_send_json_error( array( 'message' => 'Invalid keywords data' ) );
		return;
	}

	$repository = new ContaiKeywordRepository();
	$saved = 0;
	$skipped = 0;

	foreach ( $keywords_data as $item ) {
		$keyword = sanitize_text_field( $item['keyword'] ?? '' );
		$volume = isset( $item['volume'] ) ? intval( $item['volume'] ) : 0;

		if ( empty( $keyword ) ) {
			continue;
		}

		if ( $repository->exists( $keyword ) ) {
			$skipped++;
			continue;
		}

		$keywordModel = new ContaiKeyword(
			array(
				'keyword' => $keyword,
				'title' => '',
				'volume' => $volume,
				'url' => $url,
				'status' => ContaiKeyword::STATUS_PENDING,
			)
		);

		if ( $repository->create( $keywordModel ) ) {
			$saved++;
		}
	}

	wp_send_json_success(
		array(
			'message' => "Saved {$saved} keywords, skipped {$skipped} duplicates",
			'saved' => $saved,
			'skipped' => $skipped,
		)
	);
}

function contai_enqueue_content_generator_scripts() {
	$screen = get_current_screen();

	if ( ! $screen || strpos( $screen->id, 'contai-content-generator' ) === false ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only section navigation parameter.
	$section = sanitize_key( $_GET['section'] ?? 'keyword-extractor' );

	$section_js_map = array(
		'keywords-list' => 'keywords-list.js',
	);

	if ( isset( $section_js_map[ $section ] ) ) {
		$js_base_url = plugin_dir_url( __FILE__ ) . 'content-generator/assets/js/';
		wp_enqueue_script(
			"contai-content-generator-{$section}",
			$js_base_url . $section_js_map[ $section ],
			array( 'jquery' ),
			filemtime( plugin_dir_path( __FILE__ ) . "content-generator/assets/js/{$section_js_map[$section]}" ),
			true
		);
	}
}
add_action( 'admin_enqueue_scripts', 'contai_enqueue_content_generator_scripts', 20 );

function contai_content_generator_page() {
	if ( contai_render_connection_required_notice() ) {
		return;
	}

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only section navigation parameter.
	$section = sanitize_key( $_GET['section'] ?? 'keyword-extractor' );
	$valid_sections = array( 'keyword-extractor', 'post-generator', 'keywords-list', 'post-maintenance', 'generate-comments', 'legal-pages' );

	if ( ! in_array( $section, $valid_sections, true ) ) {
		$section = 'keyword-extractor';
	}

	$layout = new ContaiContentGeneratorLayout( $section );
	$layout->render_header();

	switch ( $section ) {
		case 'keyword-extractor':
			$panel = new ContaiKeywordExtractorPanel();
			$layout->render_page_title(
				__( 'Keyword Extractor', '1platform-content-ai' ),
				__( 'Analyze competitor websites and extract valuable keywords for content strategy', '1platform-content-ai' ),
				'dashicons-search'
			);
			$panel->render();
			break;

		case 'post-generator':
			$panel = new ContaiPostGeneratorPanel();
			$layout->render_page_title(
				__( 'Post Generator', '1platform-content-ai' ),
				__( 'Generate AI-powered blog posts with images, videos, and SEO metadata', '1platform-content-ai' ),
				'dashicons-edit'
			);
			$panel->render();
			break;

		case 'keywords-list':
			$panel = new ContaiKeywordsListPanel();
			$layout->render_page_title(
				__( 'Keywords List', '1platform-content-ai' ),
				__( 'View and manage all extracted keywords from your research', '1platform-content-ai' ),
				'dashicons-list-view'
			);
			$panel->render();
			break;

		case 'post-maintenance':
			$panel = new ContaiPostMaintenancePanel();
			$layout->render_page_title(
				__( 'Post Maintenance', '1platform-content-ai' ),
				__( 'Optimize and manage your existing posts with batch operations', '1platform-content-ai' ),
				'dashicons-admin-tools'
			);
			$panel->render();
			break;

		case 'generate-comments':
			$panel = new ContaiGenerateCommentsPanel();
			$layout->render_page_title(
				__( 'Generate Comments', '1platform-content-ai' ),
				__( 'Create AI-powered comments for your blog posts', '1platform-content-ai' ),
				'dashicons-admin-comments'
			);
			$panel->render();
			break;

		case 'legal-pages':
			$panel = new ContaiLegalPagesPanel();
			$layout->render_page_title(
				__( 'Legal Pages', '1platform-content-ai' ),
				__( 'Generate legal pages and manage cookie consent banner', '1platform-content-ai' ),
				'dashicons-media-text'
			);
			$panel->render();
			break;
	}

	$layout->render_footer();
}
