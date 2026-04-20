<?php
/**
 * Keywords List panel (UI v3).
 *
 * @package OnePlatformContentAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../../../database/repositories/KeywordRepository.php';
require_once __DIR__ . '/../../../services/PaginationService.php';

class ContaiKeywordsListPanel {

	private const ITEMS_PER_PAGE = 50;

	private ContaiKeywordRepository $repository;

	public function __construct() {
		$this->repository = new ContaiKeywordRepository();
	}

	public function render(): void {
		$filters    = $this->getFiltersFromRequest();
		$totalItems = $this->repository->countWithFilters( $filters['search'], $filters['status'] );

		if ( $totalItems === 0 ) {
			$this->render_empty_state();
			return;
		}

		$pagination = new ContaiPaginationService(
			$filters['page'],
			$totalItems,
			self::ITEMS_PER_PAGE
		);

		$keywords = $this->repository->findWithFilters(
			$filters['search'],
			$filters['status'],
			$filters['orderby'],
			$filters['order'],
			self::ITEMS_PER_PAGE,
			$pagination->getOffset()
		);

		?>
		<div class="contai-panel">
			<div class="contai-panel-head">
				<div class="contai-panel-head-main">
					<div class="contai-tile" aria-hidden="true">
						<span class="dashicons dashicons-list-view"></span>
					</div>
					<div>
						<h2 class="contai-panel-title"><?php esc_html_e( 'Keywords', '1platform-content-ai' ); ?></h2>
						<p class="contai-panel-desc">
							<?php
							printf(
								/* translators: %d: total keywords */
								esc_html__( '%d extracted keywords available for content generation.', '1platform-content-ai' ),
								intval( $totalItems )
							);
							?>
						</p>
					</div>
				</div>
			</div>
			<div class="contai-panel-body" style="padding: 0;">
				<div class="contai-table-wrap">
					<?php $this->render_toolbar( $filters ); ?>
					<table class="contai-table">
						<thead>
							<tr>
								<?php $this->render_sortable_header( 'keyword', __( 'Keyword', '1platform-content-ai' ), $filters ); ?>
								<?php $this->render_sortable_header( 'title', __( 'Title', '1platform-content-ai' ), $filters ); ?>
								<?php $this->render_sortable_header( 'volume', __( 'Volume', '1platform-content-ai' ), $filters ); ?>
								<?php $this->render_sortable_header( 'status', __( 'Status', '1platform-content-ai' ), $filters ); ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $keywords as $keyword ) : ?>
								<tr id="keyword-<?php echo esc_attr( $keyword->getId() ); ?>">
									<td><strong><?php echo esc_html( $keyword->getKeyword() ); ?></strong></td>
									<td><?php echo esc_html( $keyword->getTitle() ); ?></td>
									<td class="contai-mono"><?php echo esc_html( number_format( $keyword->getVolume() ) ); ?></td>
									<td>
										<span class="contai-badge <?php echo esc_attr( $this->getStatusBadgeVariant( $keyword->getStatus() ) ); ?>">
											<?php echo esc_html( ucfirst( $keyword->getStatus() ) ); ?>
										</span>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			<?php $this->render_pagination( $pagination ); ?>
		</div>
		<?php
	}

	private function getFiltersFromRequest(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only filter/sort/pagination params.
		return array(
			'page'    => isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1,
			'search'  => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : null,
			'status'  => isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : null,
			'orderby' => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'volume',
			'order'   => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'DESC',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	private function render_toolbar( array $filters ): void {
		?>
		<div class="contai-table-toolbar">
			<div class="contai-table-toolbar-left">
				<div class="contai-table-search">
					<span class="dashicons dashicons-search" aria-hidden="true"></span>
					<input
						type="text"
						id="contai-keyword-search"
						class="contai-input"
						placeholder="<?php esc_attr_e( 'Search keywords or titles…', '1platform-content-ai' ); ?>"
						value="<?php echo esc_attr( $filters['search'] ?? '' ); ?>">
				</div>
				<button type="button" class="contai-btn contai-btn-secondary contai-btn-sm contai-search-button">
					<span class="dashicons dashicons-search" aria-hidden="true"></span>
					<?php esc_html_e( 'Search', '1platform-content-ai' ); ?>
				</button>
				<select id="contai-status-filter" class="contai-select" style="width: auto;">
					<option value="all" <?php selected( $filters['status'], null ); ?>>
						<?php esc_html_e( 'All Statuses', '1platform-content-ai' ); ?>
					</option>
					<option value="active" <?php selected( $filters['status'], 'active' ); ?>>
						<?php esc_html_e( 'Active', '1platform-content-ai' ); ?>
					</option>
					<option value="inactive" <?php selected( $filters['status'], 'inactive' ); ?>>
						<?php esc_html_e( 'Inactive', '1platform-content-ai' ); ?>
					</option>
					<option value="pending" <?php selected( $filters['status'], 'pending' ); ?>>
						<?php esc_html_e( 'Pending', '1platform-content-ai' ); ?>
					</option>
				</select>
				<?php if ( $filters['search'] || $filters['status'] ) : ?>
					<button type="button" class="contai-btn contai-btn-ghost contai-btn-sm contai-clear-filters">
						<?php esc_html_e( 'Clear Filters', '1platform-content-ai' ); ?>
					</button>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function render_sortable_header( string $column, string $label, array $filters ): void {
		$isSorted     = $filters['orderby'] === $column;
		$currentOrder = $filters['order'];
		$nextOrder    = ( $isSorted && $currentOrder === 'ASC' ) ? 'DESC' : 'ASC';
		$sortClass    = $isSorted ? 'sort sorted' : 'sort';

		$url = add_query_arg(
			array(
				'orderby' => $column,
				'order'   => $nextOrder,
				's'       => $filters['search'],
				'status'  => $filters['status'],
				'paged'   => 1,
			)
		);
		?>
		<th class="<?php echo esc_attr( $sortClass ); ?>">
			<a href="<?php echo esc_url( $url ); ?>" style="color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
				<?php echo esc_html( $label ); ?>
				<?php if ( $isSorted ) : ?>
					<span class="dashicons dashicons-arrow-<?php echo esc_attr( $currentOrder === 'ASC' ? 'up' : 'down' ); ?>" aria-hidden="true"></span>
				<?php endif; ?>
			</a>
		</th>
		<?php
	}

	private function render_pagination( ContaiPaginationService $pagination ): void {
		if ( ! $pagination->hasPages() ) {
			return;
		}

		$filters    = $this->getFiltersFromRequest();
		$totalItems = $this->repository->countWithFilters( $filters['search'], $filters['status'] );
		?>
		<div class="contai-panel-foot">
			<span class="contai-panel-foot-meta">
				<?php
				printf(
					/* translators: %1$d: start item, %2$d: end item, %3$d: total items */
					esc_html__( 'Showing %1$d to %2$d of %3$d items', '1platform-content-ai' ),
					intval( $pagination->getStartItemNumber() ),
					intval( $pagination->getEndItemNumber() ),
					intval( $totalItems )
				);
				?>
			</span>
			<div class="contai-panel-foot-actions">
				<?php if ( $pagination->hasPreviousPage() ) : ?>
					<a href="<?php echo esc_url( $this->getPaginationUrl( $pagination->getPreviousPage() ) ); ?>" class="contai-btn contai-btn-secondary contai-btn-sm">
						<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
						<?php esc_html_e( 'Previous', '1platform-content-ai' ); ?>
					</a>
				<?php endif; ?>
				<?php foreach ( $pagination->getVisiblePages() as $page ) : ?>
					<?php if ( $page === $pagination->getCurrentPage() ) : ?>
						<span class="contai-btn contai-btn-primary contai-btn-sm" aria-current="page"><?php echo esc_html( $page ); ?></span>
					<?php else : ?>
						<a href="<?php echo esc_url( $this->getPaginationUrl( $page ) ); ?>" class="contai-btn contai-btn-ghost contai-btn-sm">
							<?php echo esc_html( $page ); ?>
						</a>
					<?php endif; ?>
				<?php endforeach; ?>
				<?php if ( $pagination->hasNextPage() ) : ?>
					<a href="<?php echo esc_url( $this->getPaginationUrl( $pagination->getNextPage() ) ); ?>" class="contai-btn contai-btn-secondary contai-btn-sm">
						<?php esc_html_e( 'Next', '1platform-content-ai' ); ?>
						<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
					</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function getPaginationUrl( int $page ): string {
		$filters = $this->getFiltersFromRequest();
		return add_query_arg(
			array(
				'paged'   => $page,
				's'       => $filters['search'],
				'status'  => $filters['status'],
				'orderby' => $filters['orderby'],
				'order'   => $filters['order'],
			)
		);
	}

	private function getStatusBadgeVariant( string $status ): string {
		$map = array(
			'active'   => 'contai-badge-success',
			'done'     => 'contai-badge-success',
			'inactive' => 'contai-badge-neutral',
			'pending'  => 'contai-badge-warning',
			'failed'   => 'contai-badge-danger',
		);
		return $map[ strtolower( $status ) ] ?? 'contai-badge-neutral';
	}

	private function render_empty_state(): void {
		?>
		<div class="contai-empty">
			<div class="contai-empty-icon is-primary" aria-hidden="true">
				<span class="dashicons dashicons-search"></span>
			</div>
			<h3 class="contai-empty-title">
				<?php esc_html_e( 'No keywords yet', '1platform-content-ai' ); ?>
			</h3>
			<p class="contai-empty-desc">
				<?php esc_html_e( 'Extract keywords from any topic to start generating content.', '1platform-content-ai' ); ?>
			</p>
			<div class="contai-empty-actions">
				<a href="<?php echo esc_url( add_query_arg( 'section', 'keyword-extractor', admin_url( 'admin.php?page=contai-content-generator' ) ) ); ?>" class="contai-btn contai-btn-primary">
					<span class="dashicons dashicons-search" aria-hidden="true"></span>
					<?php esc_html_e( 'Extract Keywords Now', '1platform-content-ai' ); ?>
				</a>
			</div>
		</div>
		<?php
	}
}
