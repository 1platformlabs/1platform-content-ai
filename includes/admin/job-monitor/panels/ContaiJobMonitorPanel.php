<?php
/**
 * Jobs admin panel.
 *
 * Ported from preview/table-states.html, preview/badges.html,
 * preview/stat-cards.html, preview/page-header.html, preview/tabs.html,
 * preview/empty-states.html, preview/notices.html.
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

if ( ! class_exists( 'ContaiJobMonitorPanel' ) ) {

	class ContaiJobMonitorPanel {

		private ContaiJobRepository $jobRepository;
		private ContaiJobRecoveryService $recoveryService;
		private ContaiJobMetricsService $metricsService;

		public function __construct() {
			$this->jobRepository   = new ContaiJobRepository();
			$this->recoveryService = new ContaiJobRecoveryService();
			$this->metricsService  = new ContaiJobMetricsService( $this->jobRepository );
		}

		public static function render(): void {
			( new self() )->handle();
		}

		public static function wrapperClass(): string {
			return 'wrap contai-app contai-page contai-job-monitor';
		}

		private function handle(): void {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in same condition.
			if ( isset( $_POST['recover_stuck_jobs'] ) && check_admin_referer( 'contai_recover_jobs' ) ) {
				$this->handleRecovery();
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in same condition.
			if ( isset( $_POST['delete_completed_jobs'] ) && check_admin_referer( 'contai_delete_jobs' ) ) {
				$this->handleDeleteCompleted();
			}

			$this->renderPage();
		}

		private function handleRecovery(): void {
			$jobs      = $this->jobRepository->findByStatus( ContaiJobStatus::PROCESSING );
			$recovered = $this->recoveryService->recoverStuckJobs( $jobs );

			foreach ( $recovered as $job ) {
				$this->jobRepository->update( $job );
			}

			$this->renderFlashNotice(
				'success',
				sprintf(
					/* translators: %d: recovered job count */
					__( 'Recovered %d stuck job(s).', '1platform-content-ai' ),
					count( $recovered )
				)
			);
		}

		private function handleDeleteCompleted(): void {
			global $wpdb;
			$table = $wpdb->prefix . 'contai_jobs';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table from $wpdb->prefix; safe.
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE status = %s",
					ContaiJobStatus::DONE
				)
			);

			$this->renderFlashNotice(
				'success',
				sprintf(
					/* translators: %d: deleted job count */
					__( 'Deleted %d completed job(s).', '1platform-content-ai' ),
					(int) $deleted
				)
			);
		}

		private function renderFlashNotice( string $tone, string $message ): void {
			$icons = array(
				'success' => 'dashicons-yes-alt',
				'info'    => 'dashicons-info',
				'warning' => 'dashicons-warning',
				'error'   => 'dashicons-dismiss',
			);
			$icon = $icons[ $tone ] ?? 'dashicons-info';
			?>
			<div class="contai-notice contai-notice-<?php echo esc_attr( $tone ); ?>" role="status">
				<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
				<p><?php echo esc_html( $message ); ?></p>
				<div class="contai-notice-actions"></div>
			</div>
			<?php
		}

		private function renderPage(): void {
			$metrics        = $this->metricsService->getOverviewMetrics();
			$typeBreakdown  = $this->metricsService->getJobTypeBreakdown();
			$processingJobs = $this->metricsService->getProcessingJobsWithDetails();
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only tab navigation.
			$currentTab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
			$hasStuck   = $this->hasStuckJobs( $processingJobs );
			?>
			<div class="<?php echo esc_attr( self::wrapperClass() ); ?>" data-screen-label="job-monitor">

				<div class="contai-page-header">
					<div class="contai-crumbs">
						<span><?php esc_html_e( '1Platform', '1platform-content-ai' ); ?></span>
						<span class="contai-crumbs-sep">›</span>
						<span><?php esc_html_e( 'Operations', '1platform-content-ai' ); ?></span>
						<span class="contai-crumbs-sep">›</span>
						<span class="contai-crumbs-current"><?php esc_html_e( 'Jobs', '1platform-content-ai' ); ?></span>
					</div>
					<div class="contai-page-header-row">
						<div>
							<h1 class="contai-page-title">
								<span class="contai-tile" aria-hidden="true">
									<span class="dashicons dashicons-list-view"></span>
								</span>
								<?php esc_html_e( 'Jobs', '1platform-content-ai' ); ?>
								<?php if ( $metrics['total_processing'] > 0 ) : ?>
									<span class="contai-badge contai-badge-info">
										<span class="contai-badge-dot" style="background: currentColor;"></span>
										<?php
										printf(
											/* translators: %d: currently running jobs */
											esc_html__( '%d running', '1platform-content-ai' ),
											(int) $metrics['total_processing']
										);
										?>
									</span>
								<?php endif; ?>
							</h1>
							<p class="contai-page-subtitle">
								<?php esc_html_e( 'Real-time status of the background job queue. Jobs run every 60 seconds via WP-Cron.', '1platform-content-ai' ); ?>
								<span class="contai-mono" style="margin-left: 6px;">
									<?php
									printf(
										/* translators: %s: last updated timestamp */
										esc_html__( 'Updated %s UTC', '1platform-content-ai' ),
										esc_html( gmdate( 'H:i:s' ) )
									);
									?>
								</span>
							</p>
						</div>
						<div class="contai-page-actions">
							<button type="button" class="contai-btn contai-btn-secondary" onclick="location.reload()">
								<span class="dashicons dashicons-update" aria-hidden="true"></span>
								<?php esc_html_e( 'Refresh', '1platform-content-ai' ); ?>
							</button>
							<form method="post" style="display: inline-block; margin: 0;">
								<?php wp_nonce_field( 'contai_delete_jobs' ); ?>
								<button type="submit" name="delete_completed_jobs" class="contai-btn contai-btn-secondary"
										onclick="return confirm('<?php echo esc_js( __( 'Delete all completed jobs? This cannot be undone.', '1platform-content-ai' ) ); ?>')">
									<span class="dashicons dashicons-trash" aria-hidden="true"></span>
									<?php esc_html_e( 'Clear Completed', '1platform-content-ai' ); ?>
								</button>
							</form>
							<?php if ( ! empty( $processingJobs ) ) : ?>
								<form method="post" style="display: inline-block; margin: 0;">
									<?php wp_nonce_field( 'contai_recover_jobs' ); ?>
									<button type="submit" name="recover_stuck_jobs" class="contai-btn contai-btn-primary">
										<span class="dashicons dashicons-admin-tools" aria-hidden="true"></span>
										<?php esc_html_e( 'Recover Stuck', '1platform-content-ai' ); ?>
									</button>
								</form>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<?php if ( $hasStuck ) : ?>
					<div class="contai-notice contai-notice-warning" role="alert">
						<span class="dashicons dashicons-warning" aria-hidden="true"></span>
						<p>
							<strong><?php esc_html_e( 'Stuck jobs detected.', '1platform-content-ai' ); ?></strong>
							<?php esc_html_e( 'Some jobs have been processing for more than 30 minutes. Use "Recover Stuck" to reset them.', '1platform-content-ai' ); ?>
						</p>
						<div class="contai-notice-actions"></div>
					</div>
				<?php endif; ?>

				<?php $this->renderStatsGrid( $metrics ); ?>

				<?php $this->renderTabsUnderline( $currentTab, $metrics ); ?>

				<?php $this->renderTabContent( $currentTab ); ?>

				<?php $this->renderJobTypeBreakdown( $typeBreakdown ); ?>

				<?php $this->renderCronPanel(); ?>

			</div>
			<?php
		}

		private function renderStatsGrid( array $metrics ): void {
			$slots_used = max( 0, (int) ( $metrics['processing_slots_used'] ?? 0 ) );
			$slots_pct  = min( 100, (int) round( $slots_used / 5 * 100 ) );
			?>
			<div class="contai-stat-grid">
				<div class="contai-stat">
					<div class="contai-stat-head">
						<div class="contai-stat-label"><?php esc_html_e( 'Pending', '1platform-content-ai' ); ?></div>
						<div class="contai-stat-icon" aria-hidden="true">
							<span class="dashicons dashicons-clock"></span>
						</div>
					</div>
					<div class="contai-stat-value"><?php echo esc_html( (string) $metrics['total_pending'] ); ?></div>
					<div class="contai-stat-foot">
						<span class="contai-stat-hint"><?php esc_html_e( 'Waiting in queue', '1platform-content-ai' ); ?></span>
					</div>
				</div>

				<div class="contai-stat">
					<div class="contai-stat-head">
						<div class="contai-stat-label"><?php esc_html_e( 'Processing', '1platform-content-ai' ); ?></div>
						<div class="contai-stat-icon" aria-hidden="true">
							<span class="dashicons dashicons-controls-play"></span>
						</div>
					</div>
					<div class="contai-stat-value">
						<?php echo esc_html( (string) $metrics['total_processing'] ); ?><span class="contai-stat-unit">/ 5</span>
					</div>
					<div class="contai-progress" aria-hidden="true">
						<div class="contai-progress-fill" style="width: <?php echo esc_attr( (string) $slots_pct ); ?>%"></div>
					</div>
					<div class="contai-stat-hint" style="margin-top: 8px;">
						<?php
						printf(
							/* translators: %d: used processing slots */
							esc_html__( '%d / 5 slots used', '1platform-content-ai' ),
							$slots_used
						);
						?>
					</div>
				</div>

				<div class="contai-stat">
					<div class="contai-stat-head">
						<div class="contai-stat-label"><?php esc_html_e( 'Completed', '1platform-content-ai' ); ?></div>
						<div class="contai-stat-icon" aria-hidden="true">
							<span class="dashicons dashicons-yes-alt"></span>
						</div>
					</div>
					<div class="contai-stat-value"><?php echo esc_html( (string) $metrics['total_done'] ); ?></div>
					<div class="contai-stat-foot">
						<span class="contai-stat-hint"><?php esc_html_e( 'Successfully finished', '1platform-content-ai' ); ?></span>
					</div>
				</div>

				<div class="contai-stat">
					<div class="contai-stat-head">
						<div class="contai-stat-label"><?php esc_html_e( 'Failed', '1platform-content-ai' ); ?></div>
						<div class="contai-stat-icon" aria-hidden="true">
							<span class="dashicons dashicons-dismiss"></span>
						</div>
					</div>
					<div class="contai-stat-value"><?php echo esc_html( (string) $metrics['total_failed'] ); ?></div>
					<div class="contai-stat-foot">
						<?php if ( (int) $metrics['total_failed'] > 0 ) : ?>
							<span class="contai-stat-delta is-down">
								<?php esc_html_e( 'errors', '1platform-content-ai' ); ?>
							</span>
						<?php endif; ?>
						<span class="contai-stat-hint"><?php esc_html_e( 'Unrecoverable errors', '1platform-content-ai' ); ?></span>
					</div>
				</div>
			</div>
			<?php
		}

		private function renderTabsUnderline( string $currentTab, array $metrics ): void {
			$tabs = array(
				'overview'   => array(
					'label' => __( 'Overview', '1platform-content-ai' ),
					'count' => (int) $metrics['total_pending'] + (int) $metrics['total_processing'],
					'icon'  => 'dashicons-admin-home',
				),
				'pending'    => array(
					'label' => __( 'Pending', '1platform-content-ai' ),
					'count' => (int) $metrics['total_pending'],
					'icon'  => null,
				),
				'processing' => array(
					'label' => __( 'Processing', '1platform-content-ai' ),
					'count' => (int) $metrics['total_processing'],
					'icon'  => null,
				),
				'completed'  => array(
					'label' => __( 'Completed', '1platform-content-ai' ),
					'count' => (int) $metrics['total_done'],
					'icon'  => null,
				),
				'failed'     => array(
					'label' => __( 'Failed', '1platform-content-ai' ),
					'count' => (int) $metrics['total_failed'],
					'icon'  => null,
				),
			);
			?>
			<div class="contai-tabs-underline" role="tablist" aria-label="<?php esc_attr_e( 'Job queue tabs', '1platform-content-ai' ); ?>">
				<?php foreach ( $tabs as $key => $tab ) :
					$is_active = ( $currentTab === $key );
					$url       = add_query_arg( 'tab', $key );
					?>
					<a href="<?php echo esc_url( $url ); ?>" role="tab"
						class="contai-tab <?php echo $is_active ? 'is-active' : ''; ?>"
						aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
						<?php if ( $tab['icon'] ) : ?>
							<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>" aria-hidden="true"></span>
						<?php endif; ?>
						<?php echo esc_html( $tab['label'] ); ?>
						<span class="contai-tab-count"><?php echo esc_html( (string) $tab['count'] ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
			<?php
		}

		private function renderTabContent( string $tab ): void {
			switch ( $tab ) {
				case 'pending':
					$this->renderTableForStatus( ContaiJobStatus::PENDING, __( 'Pending jobs', '1platform-content-ai' ) );
					break;
				case 'processing':
					$this->renderTableForStatus( ContaiJobStatus::PROCESSING, __( 'Processing jobs', '1platform-content-ai' ) );
					break;
				case 'completed':
					$this->renderTableForStatus( ContaiJobStatus::DONE, __( 'Completed jobs', '1platform-content-ai' ) );
					break;
				case 'failed':
					$this->renderTableForStatus( ContaiJobStatus::FAILED, __( 'Failed jobs', '1platform-content-ai' ) );
					break;
				case 'overview':
				default:
					$this->renderOverviewTable();
					break;
			}
		}

		private function renderOverviewTable(): void {
			$recent = $this->metricsService->getRecentJobs( 10 );
			$this->renderJobTable(
				$recent,
				__( 'Recent activity', '1platform-content-ai' ),
				__( 'No jobs yet', '1platform-content-ai' ),
				__( 'Queued and past jobs will appear here as soon as the first one runs.', '1platform-content-ai' )
			);
		}

		private function renderTableForStatus( string $status, string $caption ): void {
			$jobs  = $this->metricsService->getJobsByStatus( $status, 50 );
			$label = ucfirst( $status );
			$this->renderJobTable(
				$jobs,
				$caption,
				sprintf(
					/* translators: %s: status label */
					__( 'No %s jobs', '1platform-content-ai' ),
					strtolower( $label )
				),
				sprintf(
					/* translators: %s: status label lowercase */
					__( 'Nothing is currently in the %s bucket.', '1platform-content-ai' ),
					strtolower( $label )
				)
			);
		}

		private function renderJobTable( array $jobs, string $caption, string $emptyTitle, string $emptyMessage ): void {
			$count = count( $jobs );
			?>
			<div class="contai-table-wrap">
				<div class="contai-table-toolbar">
					<div class="contai-table-toolbar-left">
						<div class="contai-mono" style="text-transform: uppercase; font-size: 10.5px; letter-spacing: .06em; color: var(--fg-3);">
							<?php echo esc_html( $caption ); ?>
						</div>
					</div>
					<div>
						<?php
						printf(
							/* translators: %d: row count */
							esc_html( _n( '%d row', '%d rows', $count, '1platform-content-ai' ) ),
							$count
						);
						?>
					</div>
				</div>

				<?php if ( empty( $jobs ) ) : ?>
					<div class="contai-table-empty">
						<span class="dashicons dashicons-portfolio" aria-hidden="true"></span>
						<h4><?php echo esc_html( $emptyTitle ); ?></h4>
						<p><?php echo esc_html( $emptyMessage ); ?></p>
					</div>
				<?php else : ?>
					<table class="contai-table">
						<thead>
							<tr>
								<th style="width: 72px;"><?php esc_html_e( 'ID', '1platform-content-ai' ); ?></th>
								<th><?php esc_html_e( 'Type', '1platform-content-ai' ); ?></th>
								<th><?php esc_html_e( 'Status', '1platform-content-ai' ); ?></th>
								<th><?php esc_html_e( 'Details', '1platform-content-ai' ); ?></th>
								<th style="width: 100px;"><?php esc_html_e( 'Priority', '1platform-content-ai' ); ?></th>
								<th style="width: 80px;"><?php esc_html_e( 'Attempts', '1platform-content-ai' ); ?></th>
								<th style="width: 110px;"><?php esc_html_e( 'Created', '1platform-content-ai' ); ?></th>
								<th style="width: 90px;"><?php esc_html_e( 'Duration', '1platform-content-ai' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $jobs as $job ) : ?>
								<?php
								$is_stuck  = ContaiJobDetailsFormatter::isJobStuck( $job );
								$row_class = $is_stuck ? 'is-selected' : '';
								?>
								<tr class="<?php echo esc_attr( $row_class ); ?>">
									<td class="contai-mono">#<?php echo esc_html( (string) $job['id'] ); ?></td>
									<td><?php echo esc_html( ContaiJobDetailsFormatter::formatJobType( $job['job_type'] ) ); ?></td>
									<td>
										<?php $this->renderStatusBadge( $job['status'] ); ?>
										<?php if ( $is_stuck ) : ?>
											<span class="contai-badge contai-badge-warning" style="margin-left: 6px;">
												<span class="dashicons dashicons-warning" aria-hidden="true"></span>
												<?php esc_html_e( 'Stuck', '1platform-content-ai' ); ?>
											</span>
										<?php endif; ?>
									</td>
									<td>
										<div><?php echo esc_html( wp_strip_all_tags( ContaiJobDetailsFormatter::formatPayloadSummary( $job['payload'] ) ) ); ?></div>
										<?php if ( ! empty( $job['error_message'] ) ) : ?>
											<div class="contai-mono" style="color: var(--contai-error-text); margin-top: 4px;">
												<?php echo esc_html( $job['error_message'] ); ?>
											</div>
										<?php endif; ?>
									</td>
									<td><?php $this->renderPriorityBadge( (int) $job['priority'] ); ?></td>
									<td class="contai-mono"><?php echo esc_html( $job['attempts'] . ' / ' . $job['max_attempts'] ); ?></td>
									<td class="contai-mono">
										<span title="<?php echo esc_attr( $job['created_at'] ); ?>">
											<?php echo esc_html( ContaiJobDetailsFormatter::formatRelativeTime( $job['created_at'] ) ); ?>
										</span>
									</td>
									<td class="contai-mono">
										<?php echo esc_html( $this->renderJobDuration( $job ) ); ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
			<?php
		}

		private function renderStatusBadge( string $status ): void {
			$map = array(
				'pending'    => array( 'neutral', __( 'Pending', '1platform-content-ai' ), null ),
				'processing' => array( 'info', __( 'Processing', '1platform-content-ai' ), null ),
				'done'       => array( 'success', __( 'Done', '1platform-content-ai' ), 'dashicons-yes' ),
				'failed'     => array( 'danger', __( 'Failed', '1platform-content-ai' ), 'dashicons-dismiss' ),
			);
			list( $tone, $label, $icon ) = $map[ $status ] ?? array( 'neutral', ucfirst( $status ), null );
			?>
			<span class="contai-badge contai-badge-<?php echo esc_attr( $tone ); ?>">
				<?php if ( $icon ) : ?>
					<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
				<?php else : ?>
					<span class="contai-badge-dot" style="background: currentColor; opacity: .7;"></span>
				<?php endif; ?>
				<?php echo esc_html( $label ); ?>
			</span>
			<?php
		}

		private function renderPriorityBadge( int $priority ): void {
			if ( $priority >= 10 ) {
				$tone = 'danger';
				/* translators: %d: numeric priority value */
				$label = sprintf( __( 'High (%d)', '1platform-content-ai' ), $priority );
			} elseif ( $priority >= 5 ) {
				$tone = 'warning';
				/* translators: %d: numeric priority value */
				$label = sprintf( __( 'Medium (%d)', '1platform-content-ai' ), $priority );
			} else {
				$tone = 'neutral';
				/* translators: %d: numeric priority value */
				$label = sprintf( __( 'Normal (%d)', '1platform-content-ai' ), $priority );
			}
			?>
			<span class="contai-badge contai-badge-<?php echo esc_attr( $tone ); ?>">
				<?php echo esc_html( $label ); ?>
			</span>
			<?php
		}

		private function renderJobDuration( array $job ): string {
			if ( $job['status'] === ContaiJobStatus::PROCESSING && ! empty( $job['processed_at'] ) ) {
				$seconds = time() - strtotime( $job['processed_at'] );
				return ContaiJobDetailsFormatter::formatDuration( $seconds );
			}
			if ( $job['status'] === ContaiJobStatus::DONE && ! empty( $job['processed_at'] ) && ! empty( $job['updated_at'] ) ) {
				$seconds = strtotime( $job['updated_at'] ) - strtotime( $job['processed_at'] );
				return ContaiJobDetailsFormatter::formatDuration( $seconds );
			}
			return '—';
		}

		private function renderJobTypeBreakdown( array $breakdown ): void {
			if ( empty( $breakdown ) ) {
				return;
			}
			?>
			<div class="contai-panel">
				<div class="contai-panel-head">
					<div class="contai-panel-head-main">
						<div class="contai-tile" aria-hidden="true">
							<span class="dashicons dashicons-chart-pie"></span>
						</div>
						<div>
							<h2 class="contai-panel-title"><?php esc_html_e( 'Job type breakdown', '1platform-content-ai' ); ?></h2>
							<p class="contai-panel-desc"><?php esc_html_e( 'Totals grouped by job type across all time.', '1platform-content-ai' ); ?></p>
						</div>
					</div>
				</div>
				<div class="contai-panel-body">
					<div class="contai-stat-grid">
						<?php foreach ( $breakdown as $jobType => $stats ) : ?>
							<div class="contai-stat">
								<div class="contai-stat-head">
									<div class="contai-stat-label"><?php echo esc_html( ContaiJobDetailsFormatter::formatJobType( $jobType ) ); ?></div>
								</div>
								<div class="contai-stat-value">
									<?php echo esc_html( (string) $stats['total'] ); ?><span class="contai-stat-unit"><?php esc_html_e( 'total', '1platform-content-ai' ); ?></span>
								</div>
								<div class="contai-stat-foot" style="gap: 6px; flex-wrap: wrap;">
									<span class="contai-badge contai-badge-neutral"><?php echo esc_html( (string) $stats['pending'] ); ?> <?php esc_html_e( 'pending', '1platform-content-ai' ); ?></span>
									<span class="contai-badge contai-badge-info"><?php echo esc_html( (string) $stats['processing'] ); ?> <?php esc_html_e( 'processing', '1platform-content-ai' ); ?></span>
									<span class="contai-badge contai-badge-success"><?php echo esc_html( (string) $stats['done'] ); ?> <?php esc_html_e( 'done', '1platform-content-ai' ); ?></span>
									<span class="contai-badge contai-badge-danger"><?php echo esc_html( (string) $stats['failed'] ); ?> <?php esc_html_e( 'failed', '1platform-content-ai' ); ?></span>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
			<?php
		}

		private function renderCronPanel(): void {
			$crons   = _get_cron_array();
			$details = array(
				'next_run'   => null,
				'time_until' => null,
				'schedule'   => null,
				'registered' => false,
			);

			foreach ( (array) $crons as $timestamp => $cron ) {
				foreach ( (array) $cron as $hook => $hookDetails ) {
					if ( $hook === 'contai_process_job_queue' ) {
						$details['registered'] = true;
						$details['next_run']   = gmdate( 'Y-m-d H:i:s', $timestamp );
						$timeUntil             = human_time_diff( time(), $timestamp );
						$details['time_until'] = $timestamp > time()
							/* translators: %s: human-readable duration, e.g. "2 mins" */
							? sprintf( __( 'in %s', '1platform-content-ai' ), $timeUntil )
							/* translators: %s: human-readable duration, e.g. "2 mins" */
							: sprintf( __( '%s ago', '1platform-content-ai' ), $timeUntil );

						foreach ( $hookDetails as $data ) {
							if ( isset( $data['schedule'] ) ) {
								$details['schedule'] = $data['schedule'];
								break;
							}
						}
						break 2;
					}
				}
			}
			?>
			<div class="contai-panel">
				<div class="contai-panel-head">
					<div class="contai-panel-head-main">
						<div class="contai-tile" aria-hidden="true">
							<span class="dashicons dashicons-backup"></span>
						</div>
						<div>
							<h2 class="contai-panel-title"><?php esc_html_e( 'Cron status', '1platform-content-ai' ); ?></h2>
							<p class="contai-panel-desc"><?php esc_html_e( 'Background processor registered via WP-Cron.', '1platform-content-ai' ); ?></p>
						</div>
					</div>
					<?php if ( $details['registered'] ) : ?>
						<span class="contai-badge contai-badge-success">
							<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
							<?php esc_html_e( 'Active', '1platform-content-ai' ); ?>
						</span>
					<?php else : ?>
						<span class="contai-badge contai-badge-danger">
							<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
							<?php esc_html_e( 'Not registered', '1platform-content-ai' ); ?>
						</span>
					<?php endif; ?>
				</div>
				<div class="contai-panel-body">
					<?php if ( ! $details['registered'] ) : ?>
						<div class="contai-notice contai-notice-error" role="alert">
							<span class="dashicons dashicons-warning" aria-hidden="true"></span>
							<p>
								<strong><?php esc_html_e( 'Cron not registered.', '1platform-content-ai' ); ?></strong>
								<?php esc_html_e( 'Jobs will not process automatically. Deactivate and reactivate the plugin to re-register.', '1platform-content-ai' ); ?>
							</p>
							<div class="contai-notice-actions"></div>
						</div>
					<?php else : ?>
						<div class="contai-stat-grid">
							<div class="contai-stat">
								<div class="contai-stat-head">
									<div class="contai-stat-label"><?php esc_html_e( 'Next run', '1platform-content-ai' ); ?></div>
								</div>
								<div class="contai-stat-value" style="font-size: 18px;">
									<?php echo esc_html( $details['next_run'] ); ?>
								</div>
								<div class="contai-stat-hint" style="margin-top: 8px;">
									<?php echo esc_html( $details['time_until'] ); ?>
								</div>
							</div>
							<div class="contai-stat">
								<div class="contai-stat-head">
									<div class="contai-stat-label"><?php esc_html_e( 'Schedule', '1platform-content-ai' ); ?></div>
								</div>
								<div class="contai-stat-value" style="font-size: 18px;">
									<?php echo esc_html( $details['schedule'] ?? 'contai_every_minute' ); ?>
								</div>
								<div class="contai-stat-hint" style="margin-top: 8px;">
									<?php esc_html_e( 'Every 60 seconds', '1platform-content-ai' ); ?>
								</div>
							</div>
						</div>
					<?php endif; ?>
				</div>
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
	}
}
