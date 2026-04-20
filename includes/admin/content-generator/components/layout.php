<?php

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/sidebar.php';

class ContaiContentGeneratorLayout {

	private string $current_section;
	private ContaiContentGeneratorSidebar $sidebar;

	public function __construct( string $current_section ) {
		$this->current_section = $current_section;
		$this->sidebar         = new ContaiContentGeneratorSidebar( $current_section );
	}

	public function render_header(): void {
		?>
		<div class="wrap contai-app">
			<div class="contai-content-layout">
				<?php $this->sidebar->render(); ?>
				<main class="contai-content-main">
		<?php
	}

	public function render_footer(): void {
		?>
				</main>
			</div>
		</div>
		<?php
	}

	public function render_page_title( string $title, string $description, string $icon = 'dashicons-admin-tools' ): void {
		?>
		<div class="contai-page-header">
			<div class="contai-page-header-row">
				<div>
					<h1 class="contai-page-title">
						<span class="contai-tile" aria-hidden="true">
							<span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
						</span>
						<?php echo esc_html( $title ); ?>
					</h1>
					<p class="contai-page-subtitle">
						<?php echo esc_html( $description ); ?>
					</p>
				</div>
			</div>
		</div>
		<?php
	}
}
