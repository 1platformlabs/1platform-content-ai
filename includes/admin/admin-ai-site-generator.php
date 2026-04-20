<?php
/**
 * Site Wizard admin screen.
 *
 * Ported to UI v3 design system.
 * Foundation CSS/JS is enqueued globally from the main plugin file on every
 * plugin admin page, so no per-screen enqueue is needed here.
 *
 * @package OnePlatformContentAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../services/jobs/SiteGenerationJob.php';
require_once __DIR__ . '/../database/repositories/JobRepository.php';
require_once __DIR__ . '/../database/models/Job.php';
require_once __DIR__ . '/../services/category-api/CategoryAPIService.php';

function contai_handle_ai_site_generator_submission() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified below via wp_verify_nonce().
	if ( ! isset( $_POST['contai_start_site_generation'] ) ) {
		return;
	}

	$nonce_value = isset( $_POST['contai_site_generator_nonce'] )
		? sanitize_key( wp_unslash( $_POST['contai_site_generator_nonce'] ) )
		: '';

	if ( ! wp_verify_nonce( $nonce_value, 'contai_site_generator_nonce' ) ) {
		$GLOBALS['contai_site_gen_inline_notice'] = array(
			'type'    => 'error',
			'message' => __( 'Your session has expired. Please reload the page and try again.', '1platform-content-ai' ),
		);
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', '1platform-content-ai' ) );
	}

	try {
		$error = contai_process_site_generation_submission();
		if ( $error ) {
			$GLOBALS['contai_site_gen_inline_notice'] = $error;
			return;
		}
	} catch ( \Throwable $e ) {
		contai_log( 'Site generation submission failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		$GLOBALS['contai_site_gen_inline_notice'] = array(
			'type'    => 'error',
			'message' => __( 'An unexpected error occurred while starting site generation. Please try again.', '1platform-content-ai' ),
		);
		return;
	}
}

function contai_process_site_generation_submission() {
	$site_category = sanitize_text_field( wp_unslash( $_POST['contai_site_category'] ?? '' ) );
	if ( empty( $site_category ) ) {
		return array(
			'type'    => 'error',
			'message' => __( 'Please select a category before starting the site generation process.', '1platform-content-ai' ),
		);
	}

	require_once __DIR__ . '/../services/billing/CreditGuard.php';
	$creditGuard = new ContaiCreditGuard();
	$creditCheck = $creditGuard->validateCredits();

	if ( ! $creditCheck['has_credits'] ) {
		return array(
			'type'    => 'error',
			'message' => $creditCheck['message'],
		);
	}

	$jobRepository = new ContaiJobRepository();
	$activeJob = $jobRepository->findActiveSiteGenerationJob();

	if ( $activeJob ) {
		return array(
			'type'    => 'error',
			'message' => __( 'There is already an active site generation process running.', '1platform-content-ai' ),
		);
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
		return array(
			'type'    => 'error',
			'message' => __( 'Failed to start site generation process.', '1platform-content-ai' ),
		);
	}

	update_option( 'contai_site_language', $site_language );
	if ( ! empty( $_POST['contai_site_category'] ) ) {
		update_option( 'contai_site_category', sanitize_text_field( wp_unslash( $_POST['contai_site_category'] ) ) );
	}

	$adsense_publisher = sanitize_text_field( wp_unslash( $_POST['contai_adsense_publisher'] ?? '' ) );
	if ( ! empty( $adsense_publisher ) && preg_match( '/^pub-\d+$/', $adsense_publisher ) ) {
		update_option( 'contai_adsense_publishers', $adsense_publisher );
		if ( function_exists( 'contai_generate_adsense_ads' ) ) {
			contai_generate_adsense_ads();
		}
	}

	// Success — redirect to show the progress panel
	$redirect_url = admin_url( 'admin.php?page=contai-ai-site-generator' );
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

	$notice = $GLOBALS['contai_site_gen_inline_notice'] ?? null;

	if ( ! $notice ) {
		$notice = get_transient( 'contai_site_gen_notice' );
		if ( ! empty( $notice ) && is_array( $notice ) ) {
			delete_transient( 'contai_site_gen_notice' );
		} else {
			$notice = null;
		}
	}

	if ( $notice ) {
		$type = in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';
		$msg  = $notice['message'] ?? '';
		if ( ! empty( $msg ) ) {
			$icon_map = array(
				'success' => 'dashicons-yes-alt',
				'error'   => 'dashicons-warning',
				'warning' => 'dashicons-warning',
				'info'    => 'dashicons-info',
			);
			printf(
				'<div class="contai-app contai-notice contai-notice-%1$s"><span class="dashicons %2$s" aria-hidden="true"></span><p>%3$s</p></div>',
				esc_attr( $type ),
				esc_attr( $icon_map[ $type ] ),
				esc_html( $msg )
			);
		}
	}

	?>
	<div class="wrap contai-app contai-page">
		<div class="contai-page-header">
			<div class="contai-page-header-row">
				<div>
					<h1 class="contai-page-title">
						<span class="contai-tile" aria-hidden="true">
							<span class="dashicons dashicons-admin-site-alt3"></span>
						</span>
						<?php esc_html_e( 'Site Wizard', '1platform-content-ai' ); ?>
					</h1>
					<p class="contai-page-subtitle">
						<?php esc_html_e( 'Set up your entire website with AI-powered automation. Configure your settings and let the wizard handle the rest.', '1platform-content-ai' ); ?>
					</p>
				</div>
			</div>
		</div>

		<?php if ( $hasActiveJob ) : ?>
			<?php contai_render_active_job_status( $activeJob ); ?>
		<?php else : ?>
			<?php contai_render_last_job_notice( $jobRepository ); ?>
			<?php contai_render_site_generator_form(); ?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Render a notice about the last failed site generation job.
 *
 * @param ContaiJobRepository $jobRepository The job repository instance.
 */
function contai_render_last_job_notice( ContaiJobRepository $jobRepository ) {
	$lastJob = $jobRepository->findLastSiteGenerationJob();

	if ( ! $lastJob ) {
		return;
	}

	$status = $lastJob->getStatus();
	if ( $status !== 'failed' ) {
		return;
	}

	$errorMessage   = $lastJob->getErrorMessage() ?? __( 'Unknown error', '1platform-content-ai' );
	$completedSteps = $lastJob->getPayload()['progress']['completed_steps'] ?? array();
	$totalSteps     = $lastJob->getPayload()['progress']['total_steps'] ?? 0;
	$failedStep     = $lastJob->getPayload()['progress']['current_step_name'] ?? '';

	// Failed job notice (#55) — status === 'failed'
	?>
	<div class="contai-notice contai-notice-error">
		<span class="dashicons dashicons-warning" aria-hidden="true"></span>
		<div>
			<p><strong><?php esc_html_e( 'Previous Generation Failed', '1platform-content-ai' ); ?></strong></p>
			<p>
				<?php
				printf(
					/* translators: %1$d: completed steps, %2$d: total steps */
					esc_html__( 'The last site generation failed after completing %1$d of %2$d steps.', '1platform-content-ai' ),
					count( $completedSteps ),
					intval( $totalSteps )
				);
				?>
			</p>
			<?php if ( ! empty( $failedStep ) ) : ?>
				<p>
					<strong><?php esc_html_e( 'Failed at:', '1platform-content-ai' ); ?></strong>
					<?php echo esc_html( ucwords( str_replace( '_', ' ', $failedStep ) ) ); ?>
				</p>
			<?php endif; ?>
			<p>
				<strong><?php esc_html_e( 'Error:', '1platform-content-ai' ); ?></strong>
				<?php echo esc_html( $errorMessage ); ?>
			</p>
			<p><?php esc_html_e( 'You can re-run the wizard below to retry. Previously completed steps will be re-applied.', '1platform-content-ai' ); ?></p>
		</div>
	</div>
	<?php
}

function contai_render_active_job_status( $job ) {
	require_once __DIR__ . '/../services/billing/CreditGuard.php';
	$payload        = $job->getPayload();
	$progress       = $payload['progress'] ?? array();
	$currentStep    = $progress['current_step_name'] ?? 'Unknown';
	$completedSteps = $progress['completed_steps'] ?? array();
	$totalSteps     = $progress['total_steps'] ?? ( new ContaiSiteGenerationJob() )->getStepCount();
	$completedCount = count( $completedSteps );
	$percentage     = $totalSteps > 0 ? round( ( $completedCount / $totalSteps ) * 100 ) : 0;
	$status         = $job->getStatus();

	$statusLower  = strtolower( $status );
	$statusBadge  = 'contai-badge-info';
	if ( in_array( $statusLower, array( 'done', 'completed', 'success' ), true ) ) {
		$statusBadge = 'contai-badge-success';
	} elseif ( in_array( $statusLower, array( 'failed', 'error' ), true ) ) {
		$statusBadge = 'contai-badge-danger';
	} elseif ( in_array( $statusLower, array( 'processing', 'running' ), true ) ) {
		$statusBadge = 'contai-badge-info';
	} elseif ( in_array( $statusLower, array( 'pending', 'queued' ), true ) ) {
		$statusBadge = 'contai-badge-neutral';
	}

	?>
	<div class="contai-panel">
		<div class="contai-panel-head">
			<div class="contai-panel-head-main">
				<div class="contai-tile" aria-hidden="true">
					<span class="dashicons dashicons-update"></span>
				</div>
				<div>
					<h2 class="contai-panel-title"><?php esc_html_e( 'Site Generation In Progress', '1platform-content-ai' ); ?></h2>
					<p class="contai-panel-desc"><?php esc_html_e( 'Your website is being generated. This process may take several hours depending on the number of posts.', '1platform-content-ai' ); ?></p>
				</div>
			</div>
			<span class="contai-badge <?php echo esc_attr( $statusBadge ); ?>">
				<?php echo esc_html( $status ); ?>
			</span>
		</div>

		<div class="contai-panel-body">
			<div class="contai-progress" role="progressbar"
				aria-valuenow="<?php echo esc_attr( $percentage ); ?>"
				aria-valuemin="0" aria-valuemax="100"
				aria-label="<?php esc_attr_e( 'Site generation progress', '1platform-content-ai' ); ?>">
				<div class="contai-progress-fill" style="width: <?php echo esc_attr( $percentage ); ?>%;"></div>
			</div>
			<p class="contai-field-help" aria-live="polite" style="margin-top: 10px;">
				<?php
				printf(
					/* translators: %1$d: completion percentage, %2$d: completed steps, %3$d: total steps */
					esc_html__( '%1$d%% Complete — %2$d of %3$d steps', '1platform-content-ai' ),
					intval( $percentage ),
					intval( $completedCount ),
					intval( $totalSteps )
				);
				?>
			</p>

			<div class="contai-field" style="margin-top: 16px;">
				<div class="contai-field-head">
					<span class="contai-label">
						<span class="dashicons dashicons-controls-play" aria-hidden="true"></span>
						<?php esc_html_e( 'Current Step', '1platform-content-ai' ); ?>
					</span>
				</div>
				<p class="contai-field-help">
					<?php echo esc_html( ucwords( str_replace( '_', ' ', $currentStep ) ) ); ?>
				</p>
			</div>

			<?php if ( ! empty( $completedSteps ) ) : ?>
				<div class="contai-field" style="margin-top: 16px;">
					<div class="contai-field-head">
						<span class="contai-label">
							<span class="dashicons dashicons-yes" aria-hidden="true"></span>
							<?php esc_html_e( 'Completed Steps', '1platform-content-ai' ); ?>
						</span>
					</div>
					<ul class="contai-step-list">
						<?php foreach ( $completedSteps as $step ) : ?>
							<li>
								<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
								<?php echo esc_html( ucwords( str_replace( '_', ' ', $step ) ) ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( strtoupper( $status ) === 'FAILED' ) :
				$jobError       = $job->getErrorMessage() ?? __( 'Unknown error', '1platform-content-ai' );
				$isBalanceError = ContaiCreditGuard::isInsufficientCreditsError( $jobError )
					|| stripos( $jobError, 'Insufficient balance' ) !== false;
				?>
				<?php if ( $isBalanceError ) : ?>
					<div class="contai-notice contai-notice-warning" style="margin-top: 16px;">
						<span class="dashicons dashicons-warning" aria-hidden="true"></span>
						<div>
							<p><strong><?php esc_html_e( 'Insufficient Credits', '1platform-content-ai' ); ?></strong></p>
							<p><?php esc_html_e( 'Content generation could not complete due to insufficient balance.', '1platform-content-ai' ); ?></p>
							<p>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=contai-billing' ) ); ?>" class="contai-btn contai-btn-primary">
									<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
									<?php esc_html_e( 'Add Credits', '1platform-content-ai' ); ?>
								</a>
							</p>
						</div>
					</div>
				<?php else : ?>
					<div class="contai-notice contai-notice-error" style="margin-top: 16px;">
						<span class="dashicons dashicons-warning" aria-hidden="true"></span>
						<div>
							<p><strong><?php esc_html_e( 'Error', '1platform-content-ai' ); ?></strong></p>
							<p><?php echo esc_html( $jobError ); ?></p>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<div class="contai-panel-foot">
			<span class="contai-panel-foot-meta">
				<?php esc_html_e( 'Refresh the page to update progress.', '1platform-content-ai' ); ?>
			</span>
			<div class="contai-panel-foot-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=contai-ai-site-generator' ) ); ?>"
					class="contai-btn contai-btn-secondary"
					aria-label="<?php esc_attr_e( 'Refresh page to see updated status', '1platform-content-ai' ); ?>">
					<span class="dashicons dashicons-update" aria-hidden="true"></span>
					<?php esc_html_e( 'Refresh Status', '1platform-content-ai' ); ?>
				</a>
			</div>
		</div>
	</div>
	<?php
}

function contai_render_site_generator_form() {
	require_once __DIR__ . '/ai-site-generator/site-generator-form.php';
	contai_render_full_site_generator_form();
}
