<?php
/**
 * Apps sidebar (UI v3).
 *
 * @package OnePlatformContentAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ContaiAppsSidebar {

	private string $current_section;
	private array $menu_items;

	public function __construct( string $current_section = 'toc' ) {
		$this->current_section = $current_section;
		$this->init_menu_items();
	}

	private function init_menu_items(): void {
		$rows   = array(
			'apps'           => array( __( 'Tools', '1platform-content-ai' ), 'list-view', true ),
			'toc'            => array( __( 'Table of Contents', '1platform-content-ai' ), 'list-view', false ),
			'internal-links' => array( __( 'Internal Links', '1platform-content-ai' ), 'admin-links', false ),
			'search-console' => array( __( 'Search Console', '1platform-content-ai' ), 'cloud', false ),
			'publisuites'    => array( __( 'Link Building', '1platform-content-ai' ), 'money-alt', false ),
			'ads-manager'    => array( __( 'Ads Manager', '1platform-content-ai' ), 'megaphone', false ),
			'analytics'      => array( __( 'Google Analytics', '1platform-content-ai' ), 'chart-area', false ),
		);

		$this->menu_items = array();
		foreach ( $rows as $slug => list( $title, $icon, $home ) ) {
			$this->menu_items[ $slug ] = array(
				'title' => $title,
				'icon'  => 'dashicons-' . $icon,
				'home'  => $home,
			);
		}
	}

	public function render(): void {
		?>
		<aside class="contai-sidebar">
			<div class="contai-sidebar-head">
				<div class="contai-sidebar-logo">
					<div class="contai-sidebar-logo-tile" aria-hidden="true">
						<span class="dashicons dashicons-admin-tools" style="color:#fff;"></span>
					</div>
					<div>
						<h2><?php esc_html_e( 'Tools', '1platform-content-ai' ); ?></h2>
					</div>
				</div>
			</div>
			<nav class="contai-sidebar-section" aria-label="<?php esc_attr_e( 'Tool sections', '1platform-content-ai' ); ?>">
				<ul class="contai-sidebar-menu">
					<?php foreach ( $this->menu_items as $slug => $item ) : ?>
						<?php $this->render_menu_item( $slug, $item ); ?>
					<?php endforeach; ?>
				</ul>
			</nav>
		</aside>
		<?php
	}

	private function render_menu_item( string $slug, array $item ): void {
		$is_active  = $this->current_section === $slug;
		$item_class = $is_active ? 'is-active' : '';
		$parameters = ! $item['home'] ? array( 'section' => $slug ) : array();
		$url        = add_query_arg( $parameters, admin_url( 'admin.php?page=contai-apps' ) );
		?>
		<li class="<?php echo esc_attr( $item_class ); ?>">
			<a href="<?php echo esc_url( $url ); ?>">
				<span class="dashicons <?php echo esc_attr( $item['icon'] ); ?>" aria-hidden="true"></span>
				<span><?php echo esc_html( $item['title'] ); ?></span>
			</a>
		</li>
		<?php
	}
}
