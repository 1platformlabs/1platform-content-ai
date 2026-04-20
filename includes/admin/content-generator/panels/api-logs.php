<?php
/**
 * API Logs panel (UI v3).
 *
 * @package OnePlatformContentAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../../../database/repositories/APILogRepository.php';

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class ContaiAPILogsPanel {

	private ContaiAPILogRepository $repository;
	private string $filter_type;
	private int $per_page = 20;

	public function __construct() {
		$this->repository = new ContaiAPILogRepository();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only filter parameter.
		$this->filter_type = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all';
		$this->handle_actions();
	}

	private function handle_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified below.
		if ( ! isset( $_POST['contai_api_logs_action'] ) ) {
			return;
		}

		check_admin_referer( 'contai_api_logs_action', 'contai_api_logs_nonce' );

		if ( isset( $_POST['clear_all_logs'] ) ) {
			$this->repository->deleteAll();
			add_action( 'admin_notices', array( $this, 'render_success_notice' ) );
		} elseif ( isset( $_POST['clear_old_logs'] ) ) {
			$days    = isset( $_POST['days'] ) ? absint( wp_unslash( $_POST['days'] ) ) : 7;
			$deleted = $this->repository->deleteOlderThan( $days );
			add_action( 'admin_notices', function () use ( $deleted ) {
				?>
				<div class="contai-app contai-notice contai-notice-success">
					<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<p>
						<?php
						/* translators: %d: number of deleted log entries */
						printf( esc_html__( 'Deleted %d log entries.', '1platform-content-ai' ), intval( $deleted ) );
						?>
					</p>
				</div>
				<?php
			} );
		}
	}

	public function render_success_notice(): void {
		?>
		<div class="contai-app contai-notice contai-notice-success">
			<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
			<p><?php esc_html_e( 'All logs cleared successfully.', '1platform-content-ai' ); ?></p>
		</div>
		<?php
	}

	public function render(): void {
		$total_logs   = $this->repository->count();
		$total_errors = $this->repository->countErrors();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only pagination.
		$current_page = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$offset       = ( $current_page - 1 ) * $this->per_page;

		if ( $this->filter_type === 'errors' ) {
			$logs  = $this->repository->getErrors( $this->per_page, $offset );
			$total = $total_errors;
		} else {
			$logs  = $this->repository->getAll( $this->per_page, $offset );
			$total = $total_logs;
		}

		$total_pages = (int) ceil( $total / $this->per_page );
		?>
		<div class="contai-panel">
			<div class="contai-panel-head">
				<div class="contai-panel-head-main">
					<div class="contai-tile" aria-hidden="true">
						<span class="dashicons dashicons-list-view"></span>
					</div>
					<div>
						<h2 class="contai-panel-title"><?php esc_html_e( 'API Request Logs', '1platform-content-ai' ); ?></h2>
						<p class="contai-panel-desc"><?php esc_html_e( 'Monitor all API requests and responses.', '1platform-content-ai' ); ?></p>
					</div>
				</div>
			</div>
			<div class="contai-panel-body">
				<div class="contai-stat-grid" style="grid-template-columns: repeat(2, 1fr);">
					<div class="contai-stat">
						<div class="contai-stat-head">
							<span class="contai-stat-label"><?php esc_html_e( 'Total Requests', '1platform-content-ai' ); ?></span>
							<span class="contai-stat-icon" aria-hidden="true">
								<span class="dashicons dashicons-chart-line"></span>
							</span>
						</div>
						<div class="contai-stat-value"><?php echo esc_html( $total_logs ); ?></div>
					</div>
					<div class="contai-stat">
						<div class="contai-stat-head">
							<span class="contai-stat-label"><?php esc_html_e( 'Errors', '1platform-content-ai' ); ?></span>
							<span class="contai-stat-icon" aria-hidden="true">
								<span class="dashicons dashicons-warning"></span>
							</span>
						</div>
						<div class="contai-stat-value"><?php echo esc_html( $total_errors ); ?></div>
					</div>
				</div>

				<div class="contai-tabs-pill" role="tablist" style="margin-top: 16px;">
					<?php $this->render_filter_tab( 'all', __( 'All Logs', '1platform-content-ai' ), $total_logs ); ?>
					<?php $this->render_filter_tab( 'errors', __( 'Errors Only', '1platform-content-ai' ), $total_errors ); ?>
				</div>
			</div>

			<?php if ( empty( $logs ) ) : ?>
				<div class="contai-panel-body" style="padding-top: 0;">
					<div class="contai-empty">
						<div class="contai-empty-icon is-neutral" aria-hidden="true">
							<span class="dashicons dashicons-info"></span>
						</div>
						<p class="contai-empty-desc"><?php esc_html_e( 'No logs found.', '1platform-content-ai' ); ?></p>
					</div>
				</div>
			<?php else : ?>
				<div class="contai-table-wrap">
					<table class="contai-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', '1platform-content-ai' ); ?></th>
								<th><?php esc_html_e( 'Method', '1platform-content-ai' ); ?></th>
								<th><?php esc_html_e( 'URL', '1platform-content-ai' ); ?></th>
								<th><?php esc_html_e( 'Status', '1platform-content-ai' ); ?></th>
								<th><?php esc_html_e( 'Duration', '1platform-content-ai' ); ?></th>
								<th><?php esc_html_e( 'Actions', '1platform-content-ai' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $logs as $log ) : ?>
								<?php $this->render_log_row( $log ); ?>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="contai-panel-foot">
						<span class="contai-panel-foot-meta">
							<?php
							/* translators: %1$d: current page, %2$d: total pages */
							printf(
								esc_html__( 'Page %1$d of %2$d', '1platform-content-ai' ),
								intval( $current_page ),
								intval( $total_pages )
							);
							?>
						</span>
						<div class="contai-panel-foot-actions">
							<?php $this->render_pagination( $current_page, $total_pages ); ?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<div class="contai-panel-foot">
				<form method="post" style="display: contents;">
					<?php wp_nonce_field( 'contai_api_logs_action', 'contai_api_logs_nonce' ); ?>
					<input type="hidden" name="contai_api_logs_action" value="1">
					<input type="hidden" name="days" value="7">
					<span class="contai-panel-foot-meta">
						<?php esc_html_e( 'Log retention is managed by your plan.', '1platform-content-ai' ); ?>
					</span>
					<div class="contai-panel-foot-actions">
						<button type="submit" name="clear_old_logs" class="contai-btn contai-btn-secondary contai-btn-sm"
							onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete old logs?', '1platform-content-ai' ); ?>');">
							<span class="dashicons dashicons-trash" aria-hidden="true"></span>
							<?php esc_html_e( 'Clear logs older than 7 days', '1platform-content-ai' ); ?>
						</button>
						<button type="submit" name="clear_all_logs" class="contai-btn contai-btn-danger contai-btn-sm"
							onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete ALL logs?', '1platform-content-ai' ); ?>');">
							<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
							<?php esc_html_e( 'Clear all logs', '1platform-content-ai' ); ?>
						</button>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	private function render_filter_tab( string $filter, string $label, int $count ): void {
		$is_active = $this->filter_type === $filter;
		$class     = 'contai-tab' . ( $is_active ? ' is-active' : '' );
		$url       = add_query_arg(
			array(
				'page'    => 'contai-content-generator',
				'section' => 'api-logs',
				'filter'  => $filter,
			),
			admin_url( 'admin.php' )
		);
		?>
		<a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $class ); ?>" role="tab" aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
			<?php echo esc_html( $label ); ?>
			<span class="contai-tab-count"><?php echo esc_html( $count ); ?></span>
		</a>
		<?php
	}

	private function render_log_row( array $log ): void {
		$has_error    = ! empty( $log['error'] ) || ( isset( $log['response_code'] ) && $log['response_code'] >= 400 );
		$method       = $log['method'] ?? '';
		$method_badge = $this->getMethodBadge( $method );
		$status_badge = $this->getStatusBadge( $log['response_code'] ?? null );
		?>
		<tr>
			<td><?php echo esc_html( mysql2date( 'Y-m-d H:i:s', $log['created_at'] ) ); ?></td>
			<td>
				<span class="contai-badge <?php echo esc_attr( $method_badge ); ?>">
					<?php echo esc_html( $method ); ?>
				</span>
			</td>
			<td title="<?php echo esc_attr( $log['url'] ); ?>" class="contai-mono">
				<?php echo esc_html( $this->truncate_url( $log['url'] ) ); ?>
			</td>
			<td>
				<?php if ( isset( $log['response_code'] ) ) : ?>
					<span class="contai-badge <?php echo esc_attr( $status_badge ); ?>">
						<?php echo esc_html( $log['response_code'] ); ?>
					</span>
				<?php else : ?>
					<span class="contai-badge contai-badge-neutral">N/A</span>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( isset( $log['duration'] ) ) : ?>
					<?php echo esc_html( number_format( $log['duration'], 3 ) ); ?>s
				<?php else : ?>
					<span class="contai-field-state">—</span>
				<?php endif; ?>
			</td>
			<td>
				<button type="button" class="contai-btn contai-btn-ghost contai-btn-sm contai-view-details" data-log-id="<?php echo esc_attr( $log['id'] ); ?>">
					<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
					<?php esc_html_e( 'Details', '1platform-content-ai' ); ?>
				</button>
			</td>
		</tr>
		<tr class="contai-log-details" id="log-details-<?php echo esc_attr( $log['id'] ); ?>" style="display: none;">
			<td colspan="6">
				<?php if ( ! empty( $log['error'] ) ) : ?>
					<div style="margin-bottom: 12px;">
						<h4 style="margin:0 0 6px; font-size:12px; text-transform:uppercase; letter-spacing:.06em; color:var(--contai-error);">
							<?php esc_html_e( 'Error', '1platform-content-ai' ); ?>
						</h4>
						<pre class="contai-mono" style="background: var(--contai-error-bg); padding: 10px; border-radius: var(--contai-radius-sm); margin: 0; white-space: pre-wrap;"><?php echo esc_html( $log['error'] ); ?></pre>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $log['request_body'] ) ) : ?>
					<div style="margin-bottom: 12px;">
						<h4 style="margin:0 0 6px; font-size:12px; text-transform:uppercase; letter-spacing:.06em;">
							<?php esc_html_e( 'Request Body', '1platform-content-ai' ); ?>
						</h4>
						<pre class="contai-mono" style="background: var(--bg-2); padding: 10px; border-radius: var(--contai-radius-sm); margin: 0; white-space: pre-wrap;"><?php echo esc_html( $this->format_json( $log['request_body'] ) ); ?></pre>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $log['response_body'] ) ) : ?>
					<div>
						<h4 style="margin:0 0 6px; font-size:12px; text-transform:uppercase; letter-spacing:.06em;">
							<?php esc_html_e( 'Response Body', '1platform-content-ai' ); ?>
						</h4>
						<pre class="contai-mono" style="background: var(--bg-2); padding: 10px; border-radius: var(--contai-radius-sm); margin: 0; white-space: pre-wrap;"><?php echo esc_html( $this->format_json( $log['response_body'] ) ); ?></pre>
					</div>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private function render_pagination( int $current_page, int $total_pages ): void {
		$base_url = add_query_arg(
			array(
				'page'    => 'contai-content-generator',
				'section' => 'api-logs',
				'filter'  => $this->filter_type,
			),
			admin_url( 'admin.php' )
		);

		if ( $current_page > 1 ) :
			?>
			<a href="<?php echo esc_url( add_query_arg( 'paged', $current_page - 1, $base_url ) ); ?>" class="contai-btn contai-btn-secondary contai-btn-sm">
				<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
				<?php esc_html_e( 'Previous', '1platform-content-ai' ); ?>
			</a>
		<?php endif; ?>

		<?php if ( $current_page < $total_pages ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'paged', $current_page + 1, $base_url ) ); ?>" class="contai-btn contai-btn-secondary contai-btn-sm">
				<?php esc_html_e( 'Next', '1platform-content-ai' ); ?>
				<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
			</a>
			<?php
		endif;
	}

	private function getMethodBadge( string $method ): string {
		$map = array(
			'GET'    => 'contai-badge-info',
			'POST'   => 'contai-badge-success',
			'PUT'    => 'contai-badge-warning',
			'PATCH'  => 'contai-badge-warning',
			'DELETE' => 'contai-badge-danger',
		);
		return $map[ strtoupper( $method ) ] ?? 'contai-badge-neutral';
	}

	private function getStatusBadge( ?int $code ): string {
		if ( $code === null ) {
			return 'contai-badge-neutral';
		}
		if ( $code >= 200 && $code < 300 ) {
			return 'contai-badge-success';
		}
		if ( $code >= 400 && $code < 500 ) {
			return 'contai-badge-warning';
		}
		if ( $code >= 500 ) {
			return 'contai-badge-danger';
		}
		return 'contai-badge-neutral';
	}

	private function truncate_url( string $url, int $length = 60 ): string {
		if ( strlen( $url ) <= $length ) {
			return $url;
		}
		return substr( $url, 0, $length ) . '...';
	}

	private function format_json( ?string $json ): string {
		if ( empty( $json ) ) {
			return '';
		}
		$decoded = json_decode( $json, true );
		if ( json_last_error() === JSON_ERROR_NONE ) {
			return json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		}
		return $json;
	}
}
