<?php
/**
 * Agents admin page controller.
 *
 * Renders the server-side HTML shell for every agent view.
 * JavaScript (contai-agents-admin.js) hydrates the skeletons
 * with real data fetched from the WP REST proxy endpoints.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ContaiAgentsAdminPage {

    /**
     * Main entry point — called by the submenu page callback.
     */
    public static function render() {

        // Gate: require active API connection.
        if ( function_exists( 'contai_render_connection_required_notice' ) && contai_render_connection_required_notice() ) {
            return;
        }

        $view          = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'catalog';
        $allowed_views = array( 'catalog', 'agents', 'wizard', 'agent-detail', 'runs', 'run-detail', 'actions', 'settings' );
        if ( ! in_array( $view, $allowed_views, true ) ) {
            $view = 'catalog';
        }

        echo '<div class="wrap contai-agents-wrap">';
        echo '<h1 class="contai-agents-heading"><span class="dashicons dashicons-superhero-alt"></span> Agents</h1>';
        self::renderNav( $view );

        switch ( $view ) {
            case 'catalog':      self::renderCatalog(); break;
            case 'agents':       self::renderAgentList(); break;
            case 'wizard':       self::renderWizard(); break;
            case 'agent-detail': self::renderAgentDetail(); break;
            case 'runs':         self::renderRuns(); break;
            case 'run-detail':   self::renderRunDetail(); break;
            case 'actions':      self::renderActions(); break;
            case 'settings':     self::renderSettings(); break;
        }

        echo '</div>';
    }

    /* ── Navigation Tabs ───────────────────────────────────────── */

    private static function renderNav( $current ) {
        $base = admin_url( 'admin.php?page=contai-agents' );
        $tabs = array(
            'catalog'  => array( 'label' => 'Catalog',        'icon' => 'dashicons-screenoptions' ),
            'agents'   => array( 'label' => 'My Agents',      'icon' => 'dashicons-groups' ),
            'actions'  => array( 'label' => 'Actions',        'icon' => 'dashicons-list-view' ),
            'settings' => array( 'label' => 'Settings',       'icon' => 'dashicons-admin-generic' ),
        );

        echo '<nav class="nav-tab-wrapper contai-agents-nav">';
        foreach ( $tabs as $slug => $tab ) {
            $active = ( $current === $slug ) ? ' nav-tab-active' : '';
            printf(
                '<a href="%s&view=%s" class="nav-tab%s"><span class="dashicons %s"></span> %s</a>',
                esc_url( $base ),
                esc_attr( $slug ),
                $active,
                esc_attr( $tab['icon'] ),
                esc_html( $tab['label'] )
            );
        }
        echo '</nav>';
    }

    /* ── Catalog (template grid) ───────────────────────────────── */

    private static function renderCatalog() {
        echo '<div id="contai-agents-catalog" class="contai-agents-section">';

        // Skeleton grid (replaced by JS)
        echo '<div class="contai-agents-grid contai-skeleton-grid">';
        for ( $i = 0; $i < 6; $i++ ) {
            echo '<div class="contai-agent-card contai-skeleton">';
            echo '<div class="contai-skeleton-icon"></div>';
            echo '<div class="contai-skeleton-text"></div>';
            echo '<div class="contai-skeleton-text short"></div>';
            echo '</div>';
        }
        echo '</div>';

        // Empty state (hidden until JS decides to show it)
        echo '<div class="contai-empty-state" style="display:none;">';
        echo '<div class="contai-empty-icon-wrap"><span class="dashicons dashicons-portfolio"></span></div>';
        echo '<p class="contai-empty-title">No templates available</p>';
        echo '<p class="contai-empty-text">Please try again later.</p>';
        echo '</div>';

        echo '</div>';
    }

    /* ── Agent List (table) ────────────────────────────────────── */

    private static function renderAgentList() {
        echo '<div id="contai-agents-list" class="contai-agents-section">';

        // Toolbar
        echo '<div class="contai-agents-toolbar">';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=contai-agents&view=catalog' ) ) . '" class="button button-primary"><span class="dashicons dashicons-plus-alt2"></span> Create Agent</a>';
        echo '</div>';

        // Table
        echo '<div class="contai-table-card">';
        echo '<div class="contai-table-wrap">';
        echo '<table class="wp-list-table widefat fixed striped contai-agents-table">';
        echo '<thead><tr>';
        echo '<th>Name</th>';
        echo '<th>Template</th>';
        echo '<th>Status</th>';
        echo '<th>Last Run</th>';
        echo '<th>Actions</th>';
        echo '</tr></thead>';
        echo '<tbody id="contai-agents-tbody"><tr><td colspan="5" class="contai-loading-cell"><span class="spinner is-active"></span> Loading agents&hellip;</td></tr></tbody>';
        echo '</table>';
        echo '</div>';
        echo '</div>';

        // Empty state
        echo '<div class="contai-empty-state" style="display:none;">';
        echo '<div class="contai-empty-icon-wrap"><span class="dashicons dashicons-groups"></span></div>';
        echo '<p class="contai-empty-title">No agents yet</p>';
        echo '<p class="contai-empty-text">Create your first agent from the catalog.</p>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=contai-agents&view=catalog' ) ) . '" class="button button-primary">Go to Catalog</a>';
        echo '</div>';

        echo '</div>';
    }

    /* ── Wizard (conversational setup) ─────────────────────────── */

    private static function renderWizard() {
        $template_slug = isset( $_GET['template'] ) ? sanitize_key( $_GET['template'] ) : '';

        echo '<div id="contai-agents-wizard" class="contai-agents-section" data-template="' . esc_attr( $template_slug ) . '">';

        // Back link
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=contai-agents&view=catalog' ) ) . '" class="contai-back-link"><span class="dashicons dashicons-arrow-left-alt2"></span> Back to catalog</a>';

        // Stepper
        echo '<div class="contai-wizard-stepper">';
        echo '<span class="contai-wizard-step active" data-step="1"><span class="contai-step-num">1</span> Start</span>';
        echo '<span class="contai-wizard-step-divider"></span>';
        echo '<span class="contai-wizard-step" data-step="2"><span class="contai-step-num">2</span> Configuration</span>';
        echo '<span class="contai-wizard-step-divider"></span>';
        echo '<span class="contai-wizard-step" data-step="3"><span class="contai-step-num">3</span> Confirmation</span>';
        echo '</div>';

        // Chat area
        echo '<div class="contai-wizard-content">';
        echo '<div class="contai-wizard-messages" id="contai-wizard-messages"></div>';

        // Input area
        echo '<div class="contai-wizard-input">';
        echo '<label for="contai-wizard-message" class="screen-reader-text">Your answer</label>';
        echo '<textarea id="contai-wizard-message" rows="3" maxlength="2000" placeholder="Type your answer here..."></textarea>';
        echo '<div class="contai-wizard-input-actions">';
        echo '<button id="contai-wizard-send" class="button button-primary" disabled><span class="dashicons dashicons-yes"></span> Send</button>';
        echo '<button id="contai-wizard-confirm" class="button button-primary contai-btn-success" style="display:none;"><span class="dashicons dashicons-saved"></span> Confirm and Create Agent</button>';
        echo '</div>';
        echo '</div>';

        echo '</div>'; // .contai-wizard-content
        echo '</div>'; // #contai-agents-wizard
    }

    /* ── Agent Detail ──────────────────────────────────────────── */

    private static function renderAgentDetail() {
        $agent_id = isset( $_GET['agent_id'] ) ? sanitize_key( $_GET['agent_id'] ) : '';

        echo '<div id="contai-agent-detail" class="contai-agents-section" data-agent-id="' . esc_attr( $agent_id ) . '">';

        // Back link
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=contai-agents&view=agents' ) ) . '" class="contai-back-link"><span class="dashicons dashicons-arrow-left-alt2"></span> Back to my agents</a>';

        // Skeleton info card
        echo '<div class="contai-detail-card contai-agent-info contai-skeleton">';
        echo '<div class="contai-skeleton-text" style="width:40%;height:24px;"></div>';
        echo '<div class="contai-skeleton-text" style="width:60%;margin-top:12px;"></div>';
        echo '<div class="contai-skeleton-text short" style="width:30%;margin-top:8px;"></div>';
        echo '</div>';

        // Action bar
        echo '<div class="contai-agent-actions-bar">';
        echo '<button id="contai-run-agent" class="button button-primary" disabled><span class="dashicons dashicons-controls-play"></span> Run Agent</button>';
        echo '<a id="contai-view-runs" class="button" href="#"><span class="dashicons dashicons-backup"></span> Run History</a>';
        echo '<button id="contai-delete-agent" class="button contai-btn-danger" disabled><span class="dashicons dashicons-trash"></span> Delete</button>';
        echo '</div>';

        echo '</div>';
    }

    /* ── Runs List ─────────────────────────────────────────────── */

    private static function renderRuns() {
        $agent_id = isset( $_GET['agent_id'] ) ? sanitize_key( $_GET['agent_id'] ) : '';

        echo '<div id="contai-agents-runs" class="contai-agents-section" data-agent-id="' . esc_attr( $agent_id ) . '">';

        // Back link
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=contai-agents&view=agent-detail&agent_id=' . esc_attr( $agent_id ) ) ) . '" class="contai-back-link"><span class="dashicons dashicons-arrow-left-alt2"></span> Back to agent</a>';

        echo '<h2>Run History</h2>';

        echo '<div class="contai-table-card">';
        echo '<div class="contai-table-wrap">';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>Date</th>';
        echo '<th>Trigger</th>';
        echo '<th>Status</th>';
        echo '<th>Duration</th>';
        echo '<th>Tokens</th>';
        echo '<th></th>';
        echo '</tr></thead>';
        echo '<tbody id="contai-runs-tbody"><tr><td colspan="6" class="contai-loading-cell"><span class="spinner is-active"></span> Loading runs&hellip;</td></tr></tbody>';
        echo '</table>';
        echo '</div>';
        echo '</div>';

        // Empty state
        echo '<div class="contai-empty-state" style="display:none;">';
        echo '<div class="contai-empty-icon-wrap"><span class="dashicons dashicons-backup"></span></div>';
        echo '<p class="contai-empty-title">No runs yet</p>';
        echo '<p class="contai-empty-text">This agent has not been executed yet.</p>';
        echo '</div>';

        echo '</div>';
    }

    /* ── Run Detail ────────────────────────────────────────────── */

    private static function renderRunDetail() {
        $agent_id = isset( $_GET['agent_id'] ) ? sanitize_key( $_GET['agent_id'] ) : '';
        $run_id   = isset( $_GET['run_id'] )   ? sanitize_key( $_GET['run_id'] )   : '';

        echo '<div id="contai-run-detail" class="contai-agents-section" data-agent-id="' . esc_attr( $agent_id ) . '" data-run-id="' . esc_attr( $run_id ) . '">';

        // Back link
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=contai-agents&view=runs&agent_id=' . esc_attr( $agent_id ) ) ) . '" class="contai-back-link"><span class="dashicons dashicons-arrow-left-alt2"></span> Back to runs</a>';

        // Skeleton
        echo '<div class="contai-detail-card contai-run-info contai-skeleton">';
        echo '<div class="contai-skeleton-text" style="width:30%;height:20px;"></div>';
        echo '<div class="contai-skeleton-text" style="width:50%;margin-top:12px;"></div>';
        echo '</div>';

        // Iterations container (filled by JS)
        echo '<div id="contai-run-iterations"></div>';

        echo '</div>';
    }

    /* ── Actions Queue ─────────────────────────────────────────── */

    private static function renderActions() {
        echo '<div id="contai-agents-actions" class="contai-agents-section">';

        // Toolbar
        echo '<div class="contai-agents-toolbar">';
        echo '<select id="contai-actions-status-filter" class="contai-select">';
        echo '<option value="">All statuses</option>';
        echo '<option value="pending" selected>Pending</option>';
        echo '<option value="consumed">Consumed</option>';
        echo '</select>';
        echo '<button id="contai-dismiss-all-actions" class="button contai-btn-danger"><span class="dashicons dashicons-trash"></span> Dismiss All Pending</button>';
        echo '</div>';

        // Table
        echo '<div class="contai-table-card">';
        echo '<div class="contai-table-wrap">';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>Type</th>';
        echo '<th>Agent</th>';
        echo '<th>Date</th>';
        echo '<th>Status</th>';
        echo '<th>Actions</th>';
        echo '</tr></thead>';
        echo '<tbody id="contai-actions-tbody"><tr><td colspan="5" class="contai-loading-cell"><span class="spinner is-active"></span> Loading actions&hellip;</td></tr></tbody>';
        echo '</table>';
        echo '</div>';
        echo '</div>';

        // Empty state
        echo '<div class="contai-empty-state" style="display:none;">';
        echo '<div class="contai-empty-icon-wrap"><span class="dashicons dashicons-list-view"></span></div>';
        echo '<p class="contai-empty-title">No actions</p>';
        echo '<p class="contai-empty-text">No actions match the selected filter.</p>';
        echo '</div>';

        echo '</div>';
    }

    /* ── Settings ──────────────────────────────────────────────── */

    private static function renderSettings() {
        echo '<div id="contai-agents-settings" class="contai-agents-section">';
        echo '<div class="contai-detail-card">';
        echo '<h2 class="contai-settings-title"><span class="dashicons dashicons-admin-generic"></span> Agent Settings</h2>';
        echo '<form id="contai-agents-settings-form">';
        echo '<table class="form-table">';

        // Publish status
        echo '<tr>';
        echo '<th scope="row"><label for="contai-publish-status">Publish status</label></th>';
        echo '<td>';
        echo '<select id="contai-publish-status" name="publish_status">';
        echo '<option value="draft">Draft</option>';
        echo '<option value="publish">Publish</option>';
        echo '</select>';
        echo '<p class="description">Default status when creating content from agents. Auto-consume always creates drafts.</p>';
        echo '</td>';
        echo '</tr>';

        // Auto consume
        echo '<tr>';
        echo '<th scope="row"><label for="contai-auto-consume">Auto-consume actions</label></th>';
        echo '<td>';
        echo '<label class="contai-toggle">';
        echo '<input type="checkbox" id="contai-auto-consume" name="auto_consume" value="1">';
        echo '<span class="contai-toggle-slider"></span>';
        echo '</label>';
        echo '<p class="description">Automatically process pending agent actions using the publish status above.</p>';
        echo '</td>';
        echo '</tr>';

        // Polling interval
        echo '<tr>';
        echo '<th scope="row"><label for="contai-polling-interval">Polling interval (seconds)</label></th>';
        echo '<td>';
        echo '<input type="number" id="contai-polling-interval" name="polling_interval" min="30" max="3600" value="60" class="small-text">';
        echo '<p class="description">How often to check for new actions. Minimum 30 seconds.</p>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        echo '<p class="submit">';
        echo '<button type="submit" class="button button-primary"><span class="dashicons dashicons-yes"></span> Save Settings</button>';
        echo '</p>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }
}
