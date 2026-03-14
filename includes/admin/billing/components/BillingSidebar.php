<?php

if (!defined('ABSPATH')) exit;

class ContaiBillingSidebar
{
    private string $current_section;
    private array $menu_items;

    public function __construct(string $current_section = 'overview')
    {
        $this->current_section = $current_section;
        $this->init_menu_items();
    }

    private function init_menu_items(): void
    {
        $this->menu_items = [
            'overview' => [
                'title' => __('Overview', '1platform-content-ai'),
                'icon' => 'dashicons-chart-area',
            ],
            'billing-history' => [
                'title' => __('Billing History', '1platform-content-ai'),
                'icon' => 'dashicons-list-view',
            ],
        ];
    }

    public function render(): void
    {
        ?>
        <aside class="contai-sidebar">
            <div class="contai-sidebar-header">
                <div class="contai-sidebar-logo">
                    <span class="dashicons dashicons-money-alt"></span>
                    <h2><?php esc_html_e('Billing', '1platform-content-ai'); ?></h2>
                </div>
            </div>

            <nav class="contai-sidebar-nav">
                <ul class="contai-sidebar-menu">
                    <?php foreach ($this->menu_items as $slug => $item): ?>
                        <?php $this->render_menu_item($slug, $item); ?>
                    <?php endforeach; ?>
                </ul>
            </nav>
        </aside>
        <?php
    }

    private function render_menu_item(string $slug, array $item): void
    {
        $is_active = $this->current_section === $slug;
        $active_class = $is_active ? 'active' : '';

        $url = add_query_arg(
            ['section' => $slug],
            admin_url('admin.php?page=contai-billing')
        );
        ?>
        <li class="contai-sidebar-item <?php echo esc_attr($active_class); ?>">
            <a href="<?php echo esc_url($url); ?>" class="contai-sidebar-link">
                <span class="dashicons <?php echo esc_attr($item['icon']); ?>"></span>
                <span class="contai-sidebar-label"><?php echo esc_html($item['title']); ?></span>
            </a>
        </li>
        <?php
    }
}
