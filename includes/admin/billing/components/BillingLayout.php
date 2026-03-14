<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/BillingSidebar.php';

class ContaiBillingLayout
{
    private string $current_section;
    private ContaiBillingSidebar $sidebar;

    public function __construct(string $current_section)
    {
        $this->current_section = $current_section;
        $this->sidebar = new ContaiBillingSidebar($current_section);
    }

    public function render_header(): void
    {
        ?>
        <div class="contai-layout">
            <?php $this->sidebar->render(); ?>
            <main class="contai-main-content">
        <?php
    }

    public function render_footer(): void
    {
        ?>
            </main>
        </div>
        <?php
    }

    public function render_page_title(string $title, string $description, string $icon = 'dashicons-admin-tools'): void
    {
        ?>
        <div class="contai-page-header">
            <h1>
                <span class="dashicons <?php echo esc_attr($icon); ?>"></span>
                <?php echo esc_html($title); ?>
            </h1>
            <p class="contai-page-subtitle">
                <?php echo esc_html($description); ?>
            </p>
        </div>
        <?php
    }
}
