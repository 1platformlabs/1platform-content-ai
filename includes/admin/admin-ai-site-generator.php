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
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified below via wp_verify_nonce().
	if ( ! isset( $_POST['contai_start_site_generation'] ) ) {
		return;
	}

	$redirect_url = admin_url( 'admin.php?page=contai-ai-site-generator' );

	// Verify nonce — redirect with error instead of wp_die() to avoid silent refresh (#54)
	$nonce_value = isset( $_POST['contai_site_generator_nonce'] )
		? sanitize_key( wp_unslash( $_POST['contai_site_generator_nonce'] ) )
		: '';

	if ( ! wp_verify_nonce( $nonce_value, 'contai_site_generator_nonce' ) ) {
		set_transient( 'contai_site_gen_notice', array(
			'type'    => 'error',
			'message' => __( 'Your session has expired. Please reload the page and try again.', '1platform-content-ai' ),
		), 30 );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', '1platform-content-ai' ) );
	}

	try {
		contai_process_site_generation_submission( $redirect_url );
	} catch ( \Throwable $e ) {
		contai_log( 'Site generation submission failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		set_transient( 'contai_site_gen_notice', array(
			'type'    => 'error',
			'message' => __( 'An unexpected error occurred while starting site generation. Please try again.', '1platform-content-ai' ),
		), 30 );
		wp_safe_redirect( $redirect_url );
		exit;
	}
}

/**
 * Process the site generation form submission.
 *
 * Extracted from the handler to enable try/catch wrapping.
 * All validation errors redirect with a transient notice (#54).
 *
 * @param string $redirect_url The URL to redirect to after processing.
 */
function contai_process_site_generation_submission( string $redirect_url ) {
	// Validate that category is configured
	$site_category = sanitize_text_field( wp_unslash( $_POST['contai_site_category'] ?? '' ) );
	if ( empty( $site_category ) ) {
		set_transient( 'contai_site_gen_notice', array(
			'type'    => 'error',
			'message' => __( 'Please select a category before starting the site generation process.', '1platform-content-ai' ),
		), 30 );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	// Validate credits before starting site generation
	require_once __DIR__ . '/../services/billing/CreditGuard.php';
	$creditGuard = new ContaiCreditGuard();
	$creditCheck = $creditGuard->validateCredits();

	if ( ! $creditCheck['has_credits'] ) {
		set_transient( 'contai_site_gen_notice', array(
			'type'    => 'error',
			'message' => $creditCheck['message'],
		), 30 );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	$jobRepository = new ContaiJobRepository();
	$activeJob = $jobRepository->findActiveSiteGenerationJob();

	if ( $activeJob ) {
		set_transient( 'contai_site_gen_notice', array(
			'type'    => 'error',
			'message' => __( 'There is already an active site generation process running.', '1platform-content-ai' ),
		), 30 );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	$site_language = sanitize_text_field( wp_unslash( $_POST['contai_site_language'] ?? 'english' ) );
	$target_language = ContaiCategoryAPIService::normalizeLanguage( $site_language );

	$payload = array(
		'config' => array(
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
			'total_steps' => ( new ContaiSiteGenerationJob() )->getStepCount(),
			'started_at' => current_time( 'mysql' ),
		),
	);

	$job = ContaiJob::create( ContaiSiteGenerationJob::TYPE, $payload, 0 );
	$created = $jobRepository->create( $job );

	if ( ! $created ) {
		set_transient( 'contai_site_gen_notice', array(
			'type'    => 'error',
			'message' => __( 'Failed to start site generation process.', '1platform-content-ai' ),
		), 30 );
		wp_safe_redirect( $redirect_url );
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

	set_transient( 'contai_site_gen_notice', array(
		'type'    => 'success',
		'message' => __( 'Site generation process has been started successfully!', '1platform-content-ai' ),
	), 30 );
	wp_safe_redirect( $redirect_url );
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

	// Display transient-based notices (primary — survives redirects reliably)
	$notice = get_transient( 'contai_site_gen_notice' );
	if ( ! empty( $notice ) && is_array( $notice ) ) {
		delete_transient( 'contai_site_gen_notice' );
		$type = in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';
		$msg  = $notice['message'] ?? '';
		if ( ! empty( $msg ) ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $type ),
				esc_html( $msg )
			);
		}
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
	require_once __DIR__ . '/../services/billing/CreditGuard.php';
	$payload = $job->getPayload();
	$progress = $payload['progress'] ?? array();
	$currentStep = $progress['current_step_name'] ?? 'Unknown';
	$completedSteps = $progress['completed_steps'] ?? array();
	$totalSteps = $progress['total_steps'] ?? ( new ContaiSiteGenerationJob() )->getStepCount();
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

			<?php if ( $status === 'FAILED' ) :
				$jobError = $job->getErrorMessage() ?? 'Unknown error';
				$isBalanceError = ContaiCreditGuard::isInsufficientCreditsError( $jobError )
					|| stripos( $jobError, 'Insufficient balance' ) !== false;
			?>
				<?php if ( $isBalanceError ) : ?>
					<div class="contai-info-box contai-info-box-warning" style="margin-top: 20px;">
						<div class="contai-info-box-icon">
							<span class="dashicons dashicons-warning"></span>
						</div>
						<div class="contai-info-box-content">
							<h4><?php esc_html_e( 'Insufficient Credits', '1platform-content-ai' ); ?></h4>
							<p><?php esc_html_e( 'Content generation could not complete due to insufficient balance.', '1platform-content-ai' ); ?></p>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=contai-billing' ) ); ?>" class="button button-primary" style="margin-top: 10px;">
								<span class="dashicons dashicons-plus-alt2" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Add Credits', '1platform-content-ai' ); ?>
							</a>
						</div>
					</div>
				<?php else : ?>
					<div class="contai-info-box contai-info-box-error" style="margin-top: 20px;">
						<div class="contai-info-box-icon">
							<span class="dashicons dashicons-warning"></span>
						</div>
						<div class="contai-info-box-content">
							<h4><?php esc_html_e( 'Error', '1platform-content-ai' ); ?></h4>
							<p><?php echo esc_html( $jobError ); ?></p>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

function contai_render_site_generator_form() {
	require_once __DIR__ . '/ai-site-generator/site-generator-form.php';
	contai_render_full_site_generator_form();
}
