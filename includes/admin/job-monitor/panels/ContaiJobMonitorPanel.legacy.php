<?php
/**
 * Jobs admin panel — legacy renderer.
 *
 * Markup moved verbatim from includes/admin/admin-job-monitor.php so the
 * existing UI stays pixel-identical when the UI v3 flag is off.
 *
 * @package OnePlatformContentAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../../../services/jobs/recovery/JobRecoveryService.php';
require_once __DIR__ . '/../../../database/repositories/JobRepository.php';
require_once __DIR__ . '/../../../database/models/JobStatus.php';
require_once __DIR__ . '/../../../services/jobs/metrics/JobMetricsService.php';
require_once __DIR__ . '/../../../helpers/JobDetailsFormatter.php';

if ( ! class_exists( 'ContaiAdminJobMonitor' ) ) {
	class ContaiAdminJobMonitor {

		private ContaiJobRepository $jobRepository;
		private ContaiJobRecoveryService $recoveryService;
		private ContaiJobMetricsService $metricsService;

		public function __construct() {
			$this->jobRepository = new ContaiJobRepository();
			$this->recoveryService = new ContaiJobRecoveryService();
			$this->metricsService = new ContaiJobMetricsService( $this->jobRepository );
		}

		public function render(): void {
			$this->enqueueAssets();

	        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified via check_admin_referer() in the same condition.
			if ( isset( $_POST['recover_stuck_jobs'] ) && check_admin_referer( 'contai_recover_jobs' ) ) {
				$this->handleRecovery();
			}

	        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified via check_admin_referer() in the same condition.
			if ( isset( $_POST['delete_completed_jobs'] ) && check_admin_referer( 'contai_delete_jobs' ) ) {
				$this->handleDeleteCompleted();
			}

			$this->renderPage();
		}

		private function enqueueAssets(): void {
			$adminBase = plugin_dir_url( dirname( __DIR__, 2 ) . '/admin.php' );

			contai_enqueue_style_with_version(
				'contai-content-generator-base',
				$adminBase . 'content-generator/assets/css/base.css',
				array()
			);

			$cssUrl = $adminBase . 'assets/css/admin-job-monitor.css';
			wp_enqueue_style( 'contai-job-monitor', $cssUrl, array( 'contai-content-generator-base' ), CONTAI_VERSION );
		}

		private function handleRecovery(): void {
			$jobs = $this->jobRepository->findByStatus( ContaiJobStatus::PROCESSING );
			$recovered = $this->recoveryService->recoverStuckJobs( $jobs );

			foreach ( $recovered as $job ) {
				$this->jobRepository->update( $job );
			}

			$message = sprintf( 'Recovered %d stuck job(s)', count( $recovered ) );
			echo '<div class="notice notice-success"><p>' . esc_html( $message ) . '</p></div>';
		}

		private function handleDeleteCompleted(): void {
			global $wpdb;
			$table = $wpdb->prefix . 'contai_jobs';

	        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is from $wpdb->prefix, safe.
			$deleted = $wpdb->query(
				$wpdb->prepare(
	                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"DELETE FROM {$table} WHERE status = %s",
					ContaiJobStatus::DONE
				)
			);

			$message = sprintf( 'Deleted %d completed job(s)', $deleted );
			echo '<div class="notice notice-success"><p>' . esc_html( $message ) . '</p></div>';
		}

		private function renderPage(): void {
			$metrics = $this->metricsService->getOverviewMetrics();
			$typeBreakdown = $this->metricsService->getJobTypeBreakdown();
			$processingJobs = $this->metricsService->getProcessingJobsWithDetails();
	        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only tab navigation parameter.
			$currentTab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';

			?>
			<div class="wrap contai-job-monitor">
				<div class="contai-monitor-header">
					<h1><?php esc_html_e( 'Jobs', '1platform-content-ai' ); ?></h1>
					<div class="contai-refresh-controls">
						<span class="contai-last-updated">
							Last updated: <strong><?php echo esc_html( gmdate( 'H:i:s' ) ); ?></strong>
						</span>
						<button type="button" class="button" onclick="location.reload()">
							🔄 Refresh
						</button>
					</div>
				</div>

				<?php $this->renderStatsGrid( $metrics ); ?>

				<?php if ( ! empty( $processingJobs ) && $this->hasStuckJobs( $processingJobs ) ) : ?>
					<div class="contai-alert contai-alert-warning">
						<span>⚠️</span>
						<div>
							<strong>Warning:</strong> Some jobs appear to be stuck (running for more than 30 minutes).
							Consider recovering them.
						</div>
					</div>
				<?php endif; ?>

				<div class="contai-section">
					<div class="contai-section-header">
						<h2 class="contai-section-title">ContaiJob Queue Details</h2>
						<div class="contai-section-actions">
							<form method="post" style="display: inline-block; margin: 0;">
								<?php wp_nonce_field( 'contai_delete_jobs' ); ?>
								<button type="submit" name="delete_completed_jobs" class="button"
										onclick="return confirm('Are you sure you want to delete all completed jobs?')">
									🗑️ Clear Completed
								</button>
							</form>
							<?php if ( ! empty( $processingJobs ) ) : ?>
								<form method="post" style="display: inline-block; margin: 0;">
									<?php wp_nonce_field( 'contai_recover_jobs' ); ?>
									<button type="submit" name="recover_stuck_jobs" class="button button-primary">
										🔧 Recover Stuck
									</button>
								</form>
							<?php endif; ?>
						</div>
					</div>

					<?php $this->renderTabs( $currentTab, $metrics ); ?>
					<?php $this->renderTabContent( $currentTab ); ?>
				</div>

				<?php $this->renderJobTypeBreakdown( $typeBreakdown ); ?>
				<?php $this->renderCronSection(); ?>
				<?php $this->renderAutoRefreshScript(); ?>
			</div>
			<?php
		}

		private function renderStatsGrid( array $metrics ): void {
			$stats = array(
				array(
					'label' => 'Pending',
					'value' => $metrics['total_pending'],
					'sublabel' => 'Waiting in queue',
					'class' => 'stat-pending',
					'icon' => '⏳',
				),
				array(
					'label' => 'Processing',
					'value' => $metrics['total_processing'],
					'sublabel' => sprintf( '%d / 5 slots used', $metrics['processing_slots_used'] ),
					'class' => 'stat-processing',
					'icon' => '⚙️',
				),
				array(
					'label' => 'Completed',
					'value' => $metrics['total_done'],
					'sublabel' => 'Successfully finished',
					'class' => 'stat-done',
					'icon' => '✓',
				),
				array(
					'label' => 'Failed',
					'value' => $metrics['total_failed'],
					'sublabel' => 'Errors occurred',
					'class' => 'stat-failed',
					'icon' => '✗',
				),
			);

			echo '<div class="contai-stats-grid">';
			foreach ( $stats as $stat ) {
				?>
				<div class="contai-stat-card <?php echo esc_attr( $stat['class'] ); ?>">
					<div class="contai-stat-value">
						<?php echo esc_html( $stat['icon'] . ' ' . $stat['value'] ); ?>
					</div>
					<div class="contai-stat-label"><?php echo esc_html( $stat['label'] ); ?></div>
					<?php if ( ! empty( $stat['sublabel'] ) ) : ?>
						<div class="contai-stat-sublabel"><?php echo esc_html( $stat['sublabel'] ); ?></div>
					<?php endif; ?>
					<?php if ( $stat['class'] === 'stat-processing' ) : ?>
						<div class="contai-progress-bar">
							<div class="contai-progress-fill" style="width: <?php echo esc_attr( $metrics['processing_slots_used'] / 5 * 100 ); ?>%"></div>
						</div>
					<?php endif; ?>
				</div>
				<?php
			}
			echo '</div>';
		}

		private function renderTabs( string $currentTab, array $metrics ): void {
			$tabs = array(
				'overview' => array(
					'label' => 'Overview',
					'badge' => $metrics['total_pending'] + $metrics['total_processing'],
				),
				'pending' => array(
					'label' => 'Pending',
					'badge' => $metrics['total_pending'],
				),
				'processing' => array(
					'label' => 'Processing',
					'badge' => $metrics['total_processing'],
				),
				'completed' => array(
					'label' => 'Completed',
					'badge' => $metrics['total_done'],
				),
				'failed' => array(
					'label' => 'Failed',
					'badge' => $metrics['total_failed'],
				),
			);

			echo '<div class="contai-tabs"><ul class="contai-tab-list">';
			foreach ( $tabs as $key => $tab ) {
				$activeClass = $currentTab === $key ? 'active' : '';
				$url = add_query_arg( 'tab', $key );
				?>
				<li>
					<a href="<?php echo esc_url( $url ); ?>"
					   class="contai-tab <?php echo esc_attr( $activeClass ); ?>">
						<?php echo esc_html( $tab['label'] ); ?>
						<span class="contai-tab-badge"><?php echo esc_html( $tab['badge'] ); ?></span>
					</a>
				</li>
				<?php
			}
			echo '</ul></div>';
		}

		private function renderTabContent( string $tab ): void {
			switch ( $tab ) {
				case 'pending':
					$this->renderJobsTable( ContaiJobStatus::PENDING );
					break;
				case 'processing':
					$this->renderJobsTable( ContaiJobStatus::PROCESSING );
					break;
				case 'completed':
					$this->renderJobsTable( ContaiJobStatus::DONE );
					break;
				case 'failed':
					$this->renderJobsTable( ContaiJobStatus::FAILED );
					break;
				case 'overview':
				default:
					$this->renderOverviewTab();
					break;
			}
		}

		private function renderOverviewTab(): void {
			$recentJobs = $this->metricsService->getRecentJobs( 10 );

			if ( empty( $recentJobs ) ) {
				$this->renderEmptyState( 'No jobs found', 'The job queue is empty.' );
				return;
			}

			echo '<h3 style="margin-top: 0;">Recent Activity</h3>';
			$this->renderJobsTableHtml( $recentJobs );
		}

		private function renderJobsTable( string $status ): void {
			$jobs = $this->metricsService->getJobsByStatus( $status, 50 );

			if ( empty( $jobs ) ) {
				$statusLabel = ucfirst( $status );
				$this->renderEmptyState( "No {$statusLabel} Jobs", "There are currently no jobs with {$statusLabel} status." );
				return;
			}

			$this->renderJobsTableHtml( $jobs );
		}

		private function renderJobsTableHtml( array $jobs ): void {
			?>
			<table class="contai-jobs-table">
				<thead>
					<tr>
						<th>ID</th>
						<th>Type</th>
						<th>Status</th>
						<th>Details</th>
						<th>Priority</th>
						<th>Attempts</th>
						<th>Created</th>
						<th>Duration</th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $jobs as $job ) :
						$isStuck = ContaiJobDetailsFormatter::isJobStuck( $job );
						$rowClass = $isStuck ? 'stuck' : '';
						?>
						<tr class="<?php echo esc_attr( $rowClass ); ?>">
							<td>
								<span class="contai-job-id">#<?php echo esc_html( $job['id'] ); ?></span>
							</td>
							<td>
								<span class="contai-job-type">
									<?php echo esc_html( ContaiJobDetailsFormatter::formatJobType( $job['job_type'] ) ); ?>
								</span>
							</td>
							<td>
								<?php echo wp_kses_post( ContaiJobDetailsFormatter::formatStatus( $job['status'] ) ); ?>
								<?php if ( $isStuck ) : ?>
									<span class="contai-badge contai-badge-warning" style="margin-left: 8px;">STUCK</span>
								<?php endif; ?>
							</td>
							<td>
								<div class="contai-job-details">
									<?php echo wp_kses_post( ContaiJobDetailsFormatter::formatPayloadSummary( $job['payload'] ) ); ?>
								</div>
								<?php if ( ! empty( $job['error_message'] ) ) : ?>
									<div class="contai-job-error">
										<strong>Error:</strong> <?php echo esc_html( $job['error_message'] ); ?>
									</div>
								<?php endif; ?>
							</td>
							<td>
								<?php echo wp_kses_post( ContaiJobDetailsFormatter::formatPriority( $job['priority'] ) ); ?>
							</td>
							<td>
								<?php echo wp_kses_post( ContaiJobDetailsFormatter::formatAttempts( $job['attempts'], $job['max_attempts'] ) ); ?>
							</td>
							<td>
								<span title="<?php echo esc_attr( $job['created_at'] ); ?>">
									<?php echo esc_html( ContaiJobDetailsFormatter::formatRelativeTime( $job['created_at'] ) ); ?>
								</span>
							</td>
							<td>
								<?php
								if ( $job['status'] === ContaiJobStatus::PROCESSING && ! empty( $job['processed_at'] ) ) {
									$seconds = time() - strtotime( $job['processed_at'] );
									echo esc_html( ContaiJobDetailsFormatter::formatDuration( $seconds ) );
								} elseif ( $job['status'] === ContaiJobStatus::DONE && ! empty( $job['processed_at'] ) ) {
									$seconds = strtotime( $job['updated_at'] ) - strtotime( $job['processed_at'] );
									echo esc_html( ContaiJobDetailsFormatter::formatDuration( $seconds ) );
								} else {
									echo 'N/A';
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}

		private function renderJobTypeBreakdown( array $breakdown ): void {
			if ( empty( $breakdown ) ) {
				return;
			}

			?>
			<div class="contai-section">
				<div class="contai-section-header">
					<h2 class="contai-section-title">ContaiJob Type Breakdown</h2>
				</div>
				<div class="contai-metrics-grid">
					<?php foreach ( $breakdown as $jobType => $stats ) : ?>
						<div class="contai-metric-card">
							<div class="contai-metric-label">
								<?php echo esc_html( ContaiJobDetailsFormatter::formatJobType( $jobType ) ); ?>
							</div>
							<div class="contai-metric-value">
								<?php echo esc_html( $stats['total'] ); ?>
								<span class="contai-metric-unit">total</span>
							</div>
							<div style="margin-top: 12px; font-size: 12px; color: var(--color-gray-600);">
								<div>⏳ Pending: <?php echo esc_html( $stats['pending'] ); ?></div>
								<div>⚙️ Processing: <?php echo esc_html( $stats['processing'] ); ?></div>
								<div>✓ Done: <?php echo esc_html( $stats['done'] ); ?></div>
								<div>✗ Failed: <?php echo esc_html( $stats['failed'] ); ?></div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php
		}

		private function renderCronSection(): void {
			?>
			<div class="contai-section">
				<div class="contai-section-header">
					<h2 class="contai-section-title">Cron Status</h2>
				</div>
				<?php $this->renderCronStatus(); ?>
			</div>
			<?php
		}

		private function renderEmptyState( string $title, string $message ): void {
			?>
			<div class="contai-empty-state">
				<div class="contai-empty-state-icon">📭</div>
				<div class="contai-empty-state-text"><?php echo esc_html( $title ); ?></div>
				<p style="color: var(--color-gray-600); margin-top: 8px;">
					<?php echo esc_html( $message ); ?>
				</p>
			</div>
			<?php
		}

		private function hasStuckJobs( array $processingJobs ): bool {
			foreach ( $processingJobs as $job ) {
				if ( ContaiJobDetailsFormatter::isJobStuck( $job ) ) {
					return true;
				}
			}
			return false;
		}

		private function renderAutoRefreshScript(): void {
			$adminBase    = plugin_dir_url( dirname( __DIR__, 2 ) . '/admin.php' );
			$adminDirPath = trailingslashit( dirname( __DIR__, 2 ) );
			wp_enqueue_script(
				'tai-job-monitor-refresh',
				$adminBase . 'assets/js/tai-job-monitor-refresh.js',
				array(),
				filemtime( $adminDirPath . 'assets/js/tai-job-monitor-refresh.js' ),
				true
			);
		}

		private function renderCronStatus(): void {
			$crons = _get_cron_array();
			$found = false;

			foreach ( $crons as $timestamp => $cron ) {
				foreach ( $cron as $hook => $details ) {
					if ( $hook === 'contai_process_job_queue' ) {
						$found = true;
						$nextRun = gmdate( 'Y-m-d H:i:s', $timestamp );
						$timeUntil = human_time_diff( time(), $timestamp );
						$timeUntilFormatted = $timestamp > time() ? "in {$timeUntil}" : "{$timeUntil} ago";

						?>
						<div class="contai-metrics-grid">
							<div class="contai-metric-card">
								<div class="contai-metric-label">Next Run</div>
								<div class="contai-metric-value" style="font-size: 16px;">
									<?php echo esc_html( $nextRun ); ?>
								</div>
								<div style="margin-top: 4px; font-size: 13px; color: var(--color-gray-600);">
									<?php echo esc_html( $timeUntilFormatted ); ?>
								</div>
							</div>

							<?php foreach ( $details as $data ) : ?>
								<?php if ( isset( $data['schedule'] ) ) : ?>
									<div class="contai-metric-card">
										<div class="contai-metric-label">Schedule</div>
										<div class="contai-metric-value" style="font-size: 16px;">
											<?php echo esc_html( $data['schedule'] ); ?>
										</div>
										<div style="margin-top: 4px; font-size: 13px; color: var(--color-gray-600);">
											Every 60 seconds
										</div>
									</div>
								<?php endif; ?>
							<?php endforeach; ?>

							<div class="contai-metric-card">
								<div class="contai-metric-label">Status</div>
								<div class="contai-metric-value" style="font-size: 16px; color: var(--color-success);">
									✓ Active
								</div>
								<div style="margin-top: 4px; font-size: 13px; color: var(--color-gray-600);">
									Cron is running
								</div>
							</div>
						</div>
						<?php
					}
				}
			}

			if ( ! $found ) {
				?>
				<div class="contai-alert contai-alert-danger">
					<span>⚠️</span>
					<div>
						<strong>Cron Not Registered!</strong>
						Jobs will not process automatically. Please reactivate the plugin.
					</div>
				</div>
				<?php
			}
		}
	}
}

( new ContaiAdminJobMonitor() )->render();
