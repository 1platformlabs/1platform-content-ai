<?php
/**
 * Agents admin page controller (UI v3).
 *
 * Renders the server-side HTML shell for every agent view. JavaScript
 * (contai-agents-admin.js) hydrates the skeletons with real data fetched
 * from the WP REST proxy endpoints.
 *
 * @package OnePlatformContentAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ContaiAgentsAdminPage {

	public static function render() {
		if ( function_exists( 'contai_render_connection_required_notice' ) && contai_render_connection_required_notice() ) {
			return;
		}

		$view          = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'catalog';
		$allowed_views = array( 'catalog', 'agents', 'wizard', 'agent-detail', 'runs', 'run-detail', 'actions', 'settings' );
		if ( ! in_array( $view, $allowed_views, true ) ) {
			$view = 'catalog';
		}

		echo '<div class="wrap contai-app contai-page contai-agents">';
		self::renderHeader();
		self::renderNav( $view );

		switch ( $view ) {
			case 'catalog':
				self::renderCatalog();
				break;
			case 'agents':
				self::renderAgentList();
				break;
			case 'wizard':
				self::renderWizard();
				break;
			case 'agent-detail':
				self::renderAgentDetail();
				break;
			case 'runs':
				self::renderRuns();
				break;
			case 'run-detail':
				self::renderRunDetail();
				break;
			case 'actions':
				self::renderActions();
				break;
			case 'settings':
				self::renderSettings();
				break;
		}

		echo '</div>';
	}

	private static function renderHeader(): void {
		?>
		<div class="contai-page-header">
			<div class="contai-page-header-row">
				<div>
					<h1 class="contai-page-title">
						<span class="contai-tile" aria-hidden="true">
							<span class="dashicons dashicons-superhero-alt"></span>
						</span>
						<?php esc_html_e( 'Agents', '1platform-content-ai' ); ?>
					</h1>
					<p class="contai-page-subtitle">
						<?php esc_html_e( 'Run AI agents for content, SEO, and automation tasks.', '1platform-content-ai' ); ?>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	private static function renderNav( string $current ): void {
		$base = admin_url( 'admin.php?page=contai-agents' );
		$tabs = array(
			'catalog'  => array( 'label' => __( 'Catalog', '1platform-content-ai' ), 'icon' => 'dashicons-screenoptions' ),
			'agents'   => array( 'label' => __( 'My Agents', '1platform-content-ai' ), 'icon' => 'dashicons-groups' ),
			'actions'  => array( 'label' => __( 'Actions', '1platform-content-ai' ), 'icon' => 'dashicons-list-view' ),
			'settings' => array( 'label' => __( 'Settings', '1platform-content-ai' ), 'icon' => 'dashicons-admin-generic' ),
		);

		echo '<div class="contai-tabs-underline" role="tablist">';
		foreach ( $tabs as $slug => $tab ) {
			$active_class = ( $current === $slug ) ? ' is-active' : '';
			$aria         = ( $current === $slug ) ? 'true' : 'false';
			$url          = esc_url( $base . '&view=' . $slug );
			printf(
				'<a href="%1$s" class="contai-tab%2$s" role="tab" aria-selected="%3$s"><span class="dashicons %4$s" aria-hidden="true"></span>%5$s</a>',
				$url,
				esc_attr( $active_class ),
				esc_attr( $aria ),
				esc_attr( $tab['icon'] ),
				esc_html( $tab['label'] )
			);
		}
		echo '</div>';
	}

	private static function renderBackLink( string $href, string $label ): void {
		?>
		<a href="<?php echo esc_url( $href ); ?>" class="contai-btn contai-btn-ghost contai-btn-sm" style="align-self: flex-start;">
			<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
			<?php echo esc_html( $label ); ?>
		</a>
		<?php
	}

	private static function renderCatalog(): void {
		?>
		<div id="contai-agents-catalog" class="contai-agents-section">
			<div class="contai-agents-grid contai-skeleton-grid">
				<?php for ( $i = 0; $i < 6; $i++ ) : ?>
					<div class="contai-agent-card is-skeleton">
						<span class="contai-skeleton" style="width: 40px; height: 40px; border-radius: var(--contai-radius-md);"></span>
						<span class="contai-skeleton" style="width: 70%; height: 14px;"></span>
						<span class="contai-skeleton" style="width: 50%; height: 12px;"></span>
					</div>
				<?php endfor; ?>
			</div>
			<div class="contai-empty" style="display: none;">
				<div class="contai-empty-icon is-neutral" aria-hidden="true">
					<span class="dashicons dashicons-portfolio"></span>
				</div>
				<h3 class="contai-empty-title"><?php esc_html_e( 'No templates available', '1platform-content-ai' ); ?></h3>
				<p class="contai-empty-desc"><?php esc_html_e( 'Please try again later.', '1platform-content-ai' ); ?></p>
			</div>
		</div>
		<?php
	}

	private static function renderAgentList(): void {
		$create_url = admin_url( 'admin.php?page=contai-agents&view=catalog' );
		?>
		<div id="contai-agents-list" class="contai-agents-section">
			<div class="contai-panel">
				<div class="contai-panel-head">
					<div class="contai-panel-head-main">
						<div class="contai-tile" aria-hidden="true">
							<span class="dashicons dashicons-groups"></span>
						</div>
						<div>
							<h2 class="contai-panel-title"><?php esc_html_e( 'My Agents', '1platform-content-ai' ); ?></h2>
							<p class="contai-panel-desc"><?php esc_html_e( 'Agents you have configured from the catalog.', '1platform-content-ai' ); ?></p>
						</div>
					</div>
					<a href="<?php echo esc_url( $create_url ); ?>" class="contai-btn contai-btn-primary">
						<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
						<?php esc_html_e( 'Create Agent', '1platform-content-ai' ); ?>
					</a>
				</div>
				<div class="contai-table-wrap">
					<table class="contai-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Name', '1platform-content-ai' ); ?></th>
								<th><?php esc_html_e( 'Template', '1platform-content-ai' ); ?></th>
								<th><?php esc_html_e( 'Status', '1platform-content-ai' ); ?></th>
								<th><?php esc_html_e( 'Last Run', '1platform-content-ai' ); ?></th>
								<th><?php esc_html_e( 'Actions', '1platform-content-ai' ); ?></th>
							</tr>
						</thead>
						<tbody id="contai-agents-tbody">
							<tr>
								<td colspan="5" style="text-align: center; padding: 24px;">
									<span class="contai-spinner" aria-hidden="true"></span>
									<?php esc_html_e( 'Loading agents…', '1platform-content-ai' ); ?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
			<div class="contai-empty" style="display: none;">
				<div class="contai-empty-icon is-primary" aria-hidden="true">
					<span class="dashicons dashicons-groups"></span>
				</div>
				<h3 class="contai-empty-title"><?php esc_html_e( 'No agents yet', '1platform-content-ai' ); ?></h3>
				<p class="contai-empty-desc"><?php esc_html_e( 'Create your first agent from the catalog.', '1platform-content-ai' ); ?></p>
				<div class="contai-empty-actions">
					<a href="<?php echo esc_url( $create_url ); ?>" class="contai-btn contai-btn-primary">
						<?php esc_html_e( 'Go to Catalog', '1platform-content-ai' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
	}

	private static function renderWizard(): void {
		$template_slug = isset( $_GET['template'] ) ? sanitize_key( wp_unslash( $_GET['template'] ) ) : '';
		$catalog_url   = admin_url( 'admin.php?page=contai-agents&view=catalog' );
		?>
		<div id="contai-agents-wizard" class="contai-agents-section" data-template="<?php echo esc_attr( $template_slug ); ?>">
			<?php self::renderBackLink( $catalog_url, __( 'Back to catalog', '1platform-content-ai' ) ); ?>

			<div class="contai-stepper">
				<div class="contai-step is-active" data-step="1">
					<div class="contai-step-dot">1</div>
					<div class="contai-step-label"><?php esc_html_e( 'Start', '1platform-content-ai' ); ?></div>
				</div>
				<div class="contai-step" data-step="2">
					<div class="contai-step-dot">2</div>
					<div class="contai-step-label"><?php esc_html_e( 'Configuration', '1platform-content-ai' ); ?></div>
				</div>
				<div class="contai-step" data-step="3">
					<div class="contai-step-dot">3</div>
					<div class="contai-step-label"><?php esc_html_e( 'Confirmation', '1platform-content-ai' ); ?></div>
				</div>
			</div>

			<div class="contai-panel">
				<div class="contai-panel-body">
					<div class="contai-wizard-messages" id="contai-wizard-messages"></div>
				</div>
				<div class="contai-panel-foot">
					<div class="contai-wizard-input" style="flex: 1;">
						<label for="contai-wizard-message" class="contai-sr-only"><?php esc_html_e( 'Your answer', '1platform-content-ai' ); ?></label>
						<textarea id="contai-wizard-message" class="contai-textarea" rows="3" maxlength="2000"
							placeholder="<?php esc_attr_e( 'Type your answer here…', '1platform-content-ai' ); ?>"></textarea>
					</div>
					<div class="contai-panel-foot-actions">
						<button id="contai-wizard-send" class="contai-btn contai-btn-primary" disabled>
							<span class="dashicons dashicons-yes" aria-hidden="true"></span>
							<?php esc_html_e( 'Send', '1platform-content-ai' ); ?>
						</button>
						<button id="contai-wizard-confirm" class="contai-btn contai-btn-primary" style="display: none;">
							<span class="dashicons dashicons-saved" aria-hidden="true"></span>
							<?php esc_html_e( 'Confirm and Create Agent', '1platform-content-ai' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	private static function renderAgentDetail(): void {
		$agent_id  = isset( $_GET['agent_id'] ) ? sanitize_key( wp_unslash( $_GET['agent_id'] ) ) : '';
		$agents_url = admin_url( 'admin.php?page=contai-agents&view=agents' );
		?>
		<div id="contai-agent-detail" class="contai-agents-section" data-agent-id="<?php echo esc_attr( $agent_id ); ?>">
			<?php self::renderBackLink( $agents_url, __( 'Back to my agents', '1platform-content-ai' ) ); ?>

			<div class="contai-panel contai-skeleton" style="padding: 24px;">
				<span class="contai-skeleton" style="width: 40%; height: 20px; margin-bottom: 12px;"></span>
				<span class="contai-skeleton" style="width: 60%; height: 14px; margin-bottom: 6px;"></span>
				<span class="contai-skeleton" style="width: 30%; height: 14px;"></span>
			</div>

			<div class="contai-agent-actions-bar">
				<button id="contai-run-agent" class="contai-btn contai-btn-primary" disabled>
					<span class="dashicons dashicons-controls-play" aria-hidden="true"></span>
					<?php esc_html_e( 'Run Agent', '1platform-content-ai' ); ?>
				</button>
				<a id="contai-view-runs" class="contai-btn contai-btn-secondary" href="#">
					<span class="dashicons dashicons-backup" aria-hidden="true"></span>
					<?php esc_html_e( 'Run History', '1platform-content-ai' ); ?>
				</a>
				<button id="contai-delete-agent" class="contai-btn contai-btn-danger" disabled>
					<span class="dashicons dashicons-trash" aria-hidden="true"></span>
					<?php esc_html_e( 'Delete', '1platform-content-ai' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	private static function renderRuns(): void {
		$agent_id   = isset( $_GET['agent_id'] ) ? sanitize_key( wp_unslash( $_GET['agent_id'] ) ) : '';
		$detail_url = admin_url( 'admin.php?page=contai-agents&view=agent-detail&agent_id=' . $agent_id );
		?>
		<div id="contai-agents-runs" class="contai-agents-section" data-agent-id="<?php echo esc_attr( $agent_id ); ?>">
			<?php self::renderBackLink( $detail_url, __( 'Back to agent', '1platform-content-ai' ) ); ?>

			<div class="contai-panel">
				<div class="contai-panel-head">
					<div class="contai-panel-head-main">
						<div class="contai-tile" aria-hidden="true">
							<span class="dashicons dashicons-backup"></span>
						</div>
						<div>
							<h2 class="contai-panel-title"><?php esc_html_e( 'Run History', '1platform-content-ai' ); ?></h2>
						</div>
					</div>
				</div>
				<div class="contai-table-wrap">
					<table class="contai-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', '1platform-content-ai' ); ?></th>
								<th><?php esc_html_e( 'Trigger', '1platform-content-ai' ); ?></th>
								<th><?php esc_html_e( 'Status', '1platform-content-ai' ); ?></th>
								<th><?php esc_html_e( 'Duration', '1platform-content-ai' ); ?></th>
								<th><?php esc_html_e( 'Tokens', '1platform-content-ai' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody id="contai-runs-tbody">
							<tr>
								<td colspan="6" style="text-align: center; padding: 24px;">
									<span class="contai-spinner" aria-hidden="true"></span>
									<?php esc_html_e( 'Loading runs…', '1platform-content-ai' ); ?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<div class="contai-empty" style="display: none;">
				<div class="contai-empty-icon is-neutral" aria-hidden="true">
					<span class="dashicons dashicons-backup"></span>
				</div>
				<h3 class="contai-empty-title"><?php esc_html_e( 'No runs yet', '1platform-content-ai' ); ?></h3>
				<p class="contai-empty-desc"><?php esc_html_e( 'This agent has not been executed yet.', '1platform-content-ai' ); ?></p>
			</div>
		</div>
		<?php
	}

	private static function renderRunDetail(): void {
		$agent_id = isset( $_GET['agent_id'] ) ? sanitize_key( wp_unslash( $_GET['agent_id'] ) ) : '';
		$run_id   = isset( $_GET['run_id'] ) ? sanitize_key( wp_unslash( $_GET['run_id'] ) ) : '';
		$runs_url = admin_url( 'admin.php?page=contai-agents&view=runs&agent_id=' . $agent_id );
		?>
		<div id="contai-run-detail" class="contai-agents-section"
			data-agent-id="<?php echo esc_attr( $agent_id ); ?>"
			data-run-id="<?php echo esc_attr( $run_id ); ?>">
			<?php self::renderBackLink( $runs_url, __( 'Back to runs', '1platform-content-ai' ) ); ?>

			<div class="contai-panel contai-run-info contai-skeleton" style="padding: 20px;">
				<span class="contai-skeleton" style="width: 30%; height: 20px;"></span>
				<span class="contai-skeleton" style="width: 50%; height: 14px; margin-top: 12px;"></span>
			</div>
			<div id="contai-run-iterations"></div>
		</div>
		<?php
	}

	private static function renderActions(): void {
		?>
		<div id="contai-agents-actions" class="contai-agents-section">
			<div class="contai-panel">
				<div class="contai-panel-head">
					<div class="contai-panel-head-main">
						<div class="contai-tile" aria-hidden="true">
							<span class="dashicons dashicons-list-view"></span>
						</div>
						<div>
							<h2 class="contai-panel-title"><?php esc_html_e( 'Actions Queue', '1platform-content-ai' ); ?></h2>
							<p class="contai-panel-desc"><?php esc_html_e( 'Pending agent actions awaiting your review.', '1platform-content-ai' ); ?></p>
						</div>
					</div>
					<div class="contai-panel-foot-actions" style="border: none; background: none; padding: 0;">
						<select id="contai-actions-status-filter" class="contai-select" style="width: auto;">
							<option value=""><?php esc_html_e( 'All statuses', '1platform-content-ai' ); ?></option>
							<option value="pending" selected><?php esc_html_e( 'Pending', '1platform-content-ai' ); ?></option>
							<option value="consumed"><?php esc_html_e( 'Consumed', '1platform-content-ai' ); ?></option>
						</select>
						<button id="contai-dismiss-all-actions" class="contai-btn contai-btn-danger contai-btn-sm">
							<span class="dashicons dashicons-trash" aria-hidden="true"></span>
							<?php esc_html_e( 'Dismiss All Pending', '1platform-content-ai' ); ?>
						</button>
					</div>
				</div>
				<div class="contai-table-wrap">
					<table class="contai-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Type', '1platform-content-ai' ); ?></th>
								<th><?php esc_html_e( 'Agent', '1platform-content-ai' ); ?></th>
								<th><?php esc_html_e( 'Date', '1platform-content-ai' ); ?></th>
								<th><?php esc_html_e( 'Status', '1platform-content-ai' ); ?></th>
								<th><?php esc_html_e( 'Actions', '1platform-content-ai' ); ?></th>
							</tr>
						</thead>
						<tbody id="contai-actions-tbody">
							<tr>
								<td colspan="5" style="text-align: center; padding: 24px;">
									<span class="contai-spinner" aria-hidden="true"></span>
									<?php esc_html_e( 'Loading actions…', '1platform-content-ai' ); ?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<div class="contai-empty" style="display: none;">
				<div class="contai-empty-icon is-neutral" aria-hidden="true">
					<span class="dashicons dashicons-list-view"></span>
				</div>
				<h3 class="contai-empty-title"><?php esc_html_e( 'No actions', '1platform-content-ai' ); ?></h3>
				<p class="contai-empty-desc"><?php esc_html_e( 'No actions match the selected filter.', '1platform-content-ai' ); ?></p>
			</div>
		</div>
		<?php
	}

	private static function renderSettings(): void {
		?>
		<div id="contai-agents-settings" class="contai-agents-section">
			<div class="contai-panel">
				<div class="contai-panel-head">
					<div class="contai-panel-head-main">
						<div class="contai-tile" aria-hidden="true">
							<span class="dashicons dashicons-admin-generic"></span>
						</div>
						<div>
							<h2 class="contai-panel-title"><?php esc_html_e( 'Agent Settings', '1platform-content-ai' ); ?></h2>
							<p class="contai-panel-desc"><?php esc_html_e( 'Defaults applied when agents create content or consume actions.', '1platform-content-ai' ); ?></p>
						</div>
					</div>
				</div>
				<form id="contai-agents-settings-form">
					<div class="contai-panel-body">
						<div class="contai-form-grid">
							<div class="contai-field">
								<div class="contai-field-head">
									<label for="contai-publish-status" class="contai-label">
										<span class="dashicons dashicons-admin-post" aria-hidden="true"></span>
										<?php esc_html_e( 'Publish status', '1platform-content-ai' ); ?>
									</label>
								</div>
								<select id="contai-publish-status" name="publish_status" class="contai-select">
									<option value="draft"><?php esc_html_e( 'Draft', '1platform-content-ai' ); ?></option>
									<option value="publish"><?php esc_html_e( 'Publish', '1platform-content-ai' ); ?></option>
								</select>
								<p class="contai-field-help">
									<span class="dashicons dashicons-info" aria-hidden="true"></span>
									<?php esc_html_e( 'Default status when creating content from agents. Auto-consume always creates drafts.', '1platform-content-ai' ); ?>
								</p>
							</div>

							<div class="contai-field">
								<div class="contai-field-head">
									<label for="contai-auto-consume" class="contai-label">
										<span class="dashicons dashicons-controls-repeat" aria-hidden="true"></span>
										<?php esc_html_e( 'Auto-consume actions', '1platform-content-ai' ); ?>
									</label>
								</div>
								<label style="display: inline-flex; align-items: center; gap: 8px;">
									<input type="checkbox" id="contai-auto-consume" name="auto_consume" value="1">
									<span><?php esc_html_e( 'Enable auto-consume', '1platform-content-ai' ); ?></span>
								</label>
								<p class="contai-field-help">
									<span class="dashicons dashicons-info" aria-hidden="true"></span>
									<?php esc_html_e( 'Automatically process pending agent actions using the publish status above.', '1platform-content-ai' ); ?>
								</p>
							</div>

							<div class="contai-field contai-form-grid-full">
								<div class="contai-field-head">
									<label for="contai-polling-interval" class="contai-label">
										<span class="dashicons dashicons-clock" aria-hidden="true"></span>
										<?php esc_html_e( 'Polling interval (seconds)', '1platform-content-ai' ); ?>
									</label>
								</div>
								<input type="number" id="contai-polling-interval" name="polling_interval" min="30" max="3600" value="60" class="contai-input">
								<p class="contai-field-help">
									<span class="dashicons dashicons-info" aria-hidden="true"></span>
									<?php esc_html_e( 'How often to check for new actions. Minimum 30 seconds.', '1platform-content-ai' ); ?>
								</p>
							</div>
						</div>
					</div>
					<div class="contai-panel-foot">
						<span class="contai-panel-foot-meta">
							<?php esc_html_e( 'Changes apply to future agent runs only.', '1platform-content-ai' ); ?>
						</span>
						<div class="contai-panel-foot-actions">
							<button type="submit" class="contai-btn contai-btn-primary">
								<span class="dashicons dashicons-yes" aria-hidden="true"></span>
								<?php esc_html_e( 'Save Settings', '1platform-content-ai' ); ?>
							</button>
						</div>
					</div>
				</form>
			</div>
		</div>
		<?php
	}
}
