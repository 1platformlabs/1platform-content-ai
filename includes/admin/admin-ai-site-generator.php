<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../services/jobs/SiteGenerationJob.php';
require_once __DIR__ . '/../database/repositories/JobRepository.php';
require_once __DIR__ . '/../database/models/Job.php';
require_once __DIR__ . '/../services/category-api/CategoryAPIService.php';

function contai_enqueue_ai_site_generator_styles() {
	$screen = get_current_screen();

	if ( ! $screen ) {
		return;
	}

	if ( strpos( $screen->id, 'contai-ai-site-generator' ) !== false ) {
		contai_enqueue_style_with_version(
			'contai-content-generator-base',
			plugin_dir_url( __FILE__ ) . 'content-generator/assets/css/base.css',
			array()
		);

		contai_enqueue_style_with_version(
			'contai-ai-site-generator',
			plugin_dir_url( __FILE__ ) . 'assets/css/admin-ai-site-generator.css',
			array( 'contai-content-generator-base' )
		);
	}
}
add_action( 'admin_enqueue_scripts', 'contai_enqueue_ai_site_generator_styles', 20 );

function contai_handle_ai_site_generator_submission() {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified below via check_admin_referer().
	if ( ! isset( $_POST['contai_start_site_generation'] ) ) {
		return;
	}

	check_admin_referer( 'contai_site_generator_nonce', 'contai_site_generator_nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', '1platform-content-ai' ) );
	}

	// Validate that category is configured
	$site_category = sanitize_text_field( wp_unslash( $_POST['contai_site_category'] ?? '' ) );
	if ( empty( $site_category ) ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'contai-ai-site-generator',
					'error' => 1,
					'message' => 'Please select a category before starting the site generation process.',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	$jobRepository = new ContaiJobRepository();
	$activeJob = $jobRepository->findActiveSiteGenerationJob();

	if ( $activeJob ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'contai-ai-site-generator',
					'error' => 1,
					'message' => 'There is already an active site generation process running.',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	$site_language = sanitize_text_field( wp_unslash( $_POST['contai_site_language'] ?? 'english' ) );
	$target_language = ContaiCategoryAPIService::normalizeLanguage( $site_language );

	$payload = array(
		'config' => array(
			'license_key' => get_option( 'contai_api_key', '' ),
			'site_config' => array(
				'site_topic' => sanitize_text_field( wp_unslash( $_POST['contai_site_topic'] ?? '' ) ),
				'site_language' => $site_language,
				'site_category' => sanitize_text_field( wp_unslash( $_POST['contai_site_category'] ?? '' ) ),
				'wordpress_theme' => sanitize_text_field( wp_unslash( $_POST['contai_wordpress_theme'] ?? 'astra' ) ),
			),
			'legal_info' => array(
				'owner' => sanitize_text_field( wp_unslash( $_POST['contai_legal_owner'] ?? '' ) ),
				'email' => sanitize_email( wp_unslash( $_POST['contai_legal_email'] ?? '' ) ),
				'address' => sanitize_text_field( wp_unslash( $_POST['contai_legal_address'] ?? '' ) ),
				'activity' => sanitize_text_field( wp_unslash( $_POST['contai_legal_activity'] ?? '' ) ),
			),
			'keyword_extraction' => array(
				'source_topic' => sanitize_text_field( wp_unslash( $_POST['contai_source_topic'] ?? '' ) ),
				'target_country' => sanitize_text_field( wp_unslash( $_POST['contai_target_country'] ?? 'us' ) ),
				'target_language' => $target_language,
			),
			'post_generation' => array(
				'num_posts' => absint( $_POST['contai_num_posts'] ?? 100 ),
				'target_country' => sanitize_text_field( wp_unslash( $_POST['contai_target_country'] ?? 'us' ) ),
				'target_language' => $target_language,
				'image_provider' => sanitize_text_field( wp_unslash( $_POST['contai_image_provider'] ?? 'pexels' ) ),
			),
			'comments' => array(
				'num_posts' => absint( $_POST['contai_num_posts'] ?? 100 ),
				'comments_per_post' => absint( $_POST['contai_comments_per_post'] ?? 1 ),
			),
			'adsense' => array(
				'publisher_id' => sanitize_text_field( wp_unslash( $_POST['contai_adsense_publisher'] ?? '' ) ),
			),
		),
		'progress' => array(
			'current_step' => 0,
			'current_step_name' => '',
			'completed_steps' => array(),
			'total_steps' => 11,
			'started_at' => current_time( 'mysql' ),
		),
	);

	$job = ContaiJob::create( ContaiSiteGenerationJob::TYPE, $payload, 0 );
	$created = $jobRepository->create( $job );

	if ( ! $created ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'contai-ai-site-generator',
					'error' => 1,
					'message' => 'Failed to start site generation process.',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// Save site configuration to options for use by other services
	update_option( 'contai_site_language', $site_language );
	if ( ! empty( $_POST['contai_site_category'] ) ) {
		update_option( 'contai_site_category', sanitize_text_field( wp_unslash( $_POST['contai_site_category'] ) ) );
	}

	// Save AdSense publisher ID immediately so it appears in Ads Manager
	// before the background job completes (fixes #12)
	$adsense_publisher = sanitize_text_field( wp_unslash( $_POST['contai_adsense_publisher'] ?? '' ) );
	if ( ! empty( $adsense_publisher ) && preg_match( '/^pub-\d+$/', $adsense_publisher ) ) {
		update_option( 'contai_adsense_publishers', $adsense_publisher );
		if ( function_exists( 'contai_generate_adsense_ads' ) ) {
			contai_generate_adsense_ads();
		}
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page' => 'contai-ai-site-generator',
				'success' => 1,
				'message' => urlencode( 'Site generation process has been started successfully!' ),
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}
add_action( 'admin_init', 'contai_handle_ai_site_generator_submission' );

function contai_ai_site_generator_page() {
	if ( contai_render_connection_required_notice() ) {
		return;
	}

	$jobRepository = new ContaiJobRepository();
	$activeJob = $jobRepository->findActiveSiteGenerationJob();
	$hasActiveJob = ! empty( $activeJob );

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only read of GET params after redirect.
	if ( isset( $_GET['success'] ) ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : 'Success';
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only read of GET params after redirect.
	if ( isset( $_GET['error'] ) ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : 'Error';
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}

	?>
	<div class="wrap contai-wizard-wrap">
		<div class="contai-wizard-header">
			<div class="contai-wizard-header-content">
				<div class="contai-wizard-icon">
					<span class="dashicons dashicons-admin-site-alt3"></span>
				</div>
				<div class="contai-wizard-header-text">
					<h1><?php esc_html_e( 'Site Wizard', '1platform-content-ai' ); ?></h1>
					<p><?php esc_html_e( 'Set up your entire website with AI-powered automation. Configure your settings and let the wizard handle the rest.', '1platform-content-ai' ); ?></p>
				</div>
			</div>
		</div>

		<?php if ( $hasActiveJob ) : ?>
			<?php contai_render_active_job_status( $activeJob ); ?>
		<?php else : ?>
			<?php contai_render_site_generator_form(); ?>
		<?php endif; ?>
	</div>
	<?php
}

function contai_render_active_job_status( $job ) {
	$payload = $job->getPayload();
	$progress = $payload['progress'] ?? array();
	$currentStep = $progress['current_step_name'] ?? 'Unknown';
	$completedSteps = $progress['completed_steps'] ?? array();
	$totalSteps = $progress['total_steps'] ?? 11;
	$completedCount = count( $completedSteps );
	$percentage = $totalSteps > 0 ? round( ( $completedCount / $totalSteps ) * 100 ) : 0;
	$status = $job->getStatus();

	?>
	<div class="contai-settings-panel">
		<div class="contai-panel-header">
			<div class="contai-panel-title-group">
				<h2 class="contai-panel-title">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Site Generation In Progress', '1platform-content-ai' ); ?>
				</h2>
				<p class="contai-panel-description">
					<?php esc_html_e( 'Your website is being generated. This process may take several hours depending on the number of posts.', '1platform-content-ai' ); ?>
				</p>
			</div>
		</div>

		<div class="contai-panel-body">
			<div class="contai-progress-container">
				<div class="contai-progress-bar" role="progressbar" aria-valuenow="<?php echo esc_attr( $percentage ); ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( 'Site generation progress', '1platform-content-ai' ); ?>">
					<div class="contai-progress-fill" style="width: <?php echo esc_attr( $percentage ); ?>%;"></div>
				</div>
				<div class="contai-progress-text" aria-live="polite">
					<?php
					printf(
						/* translators: %1$d: completion percentage, %2$d: number of completed steps, %3$d: total number of steps */
						esc_html__( '%1$d%% Complete – %2$d of %3$d Steps', '1platform-content-ai' ),
						intval( $percentage ),
						intval( $completedCount ),
						intval( $totalSteps )
					);
					?>
				</div>
			</div>

			<div class="contai-current-step">
				<strong><?php esc_html_e( 'Current Step:', '1platform-content-ai' ); ?></strong>
				<span><?php echo esc_html( ucwords( str_replace( '_', ' ', $currentStep ) ) ); ?></span>
			</div>

			<div class="contai-job-status">
				<strong><?php esc_html_e( 'Status:', '1platform-content-ai' ); ?></strong>
				<span class="contai-status-badge contai-status-<?php echo esc_attr( strtolower( $status ) ); ?>">
					<?php echo esc_html( $status ); ?>
				</span>
			</div>

			<?php if ( ! empty( $completedSteps ) ) : ?>
				<div class="contai-completed-steps">
					<h3><?php esc_html_e( 'Completed Steps:', '1platform-content-ai' ); ?></h3>
					<ul>
						<?php foreach ( $completedSteps as $step ) : ?>
							<li>
								<span class="dashicons dashicons-yes"></span>
								<?php echo esc_html( ucwords( str_replace( '_', ' ', $step ) ) ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<div class="contai-button-group" style="margin-top: 20px;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=contai-ai-site-generator' ) ); ?>" class="button button-primary" aria-label="<?php esc_attr_e( 'Refresh page to see updated status', '1platform-content-ai' ); ?>">
					<span class="dashicons dashicons-update" aria-hidden="true"></span>
					<?php esc_html_e( 'Refresh Status', '1platform-content-ai' ); ?>
				</a>
			</div>

			<?php if ( $status === 'FAILED' ) : ?>
				<div class="contai-info-box contai-info-box-error" style="margin-top: 20px;">
					<div class="contai-info-box-icon">
						<span class="dashicons dashicons-warning"></span>
					</div>
					<div class="contai-info-box-content">
						<h4><?php esc_html_e( 'Error', '1platform-content-ai' ); ?></h4>
						<p><?php echo esc_html( $job->getErrorMessage() ?? 'Unknown error' ); ?></p>
					</div>
				</div>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

function contai_render_site_generator_form() {
	require_once __DIR__ . '/ai-site-generator/site-generator-form.php';
	contai_render_full_site_generator_form();
}
