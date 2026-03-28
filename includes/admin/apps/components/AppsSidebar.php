<?php

if (!defined('ABSPATH')) exit;

class ContaiAppsSidebar
{

    private string $current_section;
    private array $menu_items;

    public function __construct(string $current_section = 'toc')
    {
        $this->current_section = $current_section;
        $this->init_menu_items();
    }

    private function init_menu_items(): void
    {
        $this->menu_items = [
            'apps' => [
                'title' => __('Tools', '1platform-content-ai'),
                'icon' => 'dashicons-list-view',
                'badge' => null,
                'home' => true
            ],
            'toc' => [
                'title' => __('Table of Contents', '1platform-content-ai'),
                'icon' => 'dashicons-list-view',
                'badge' => null,
                'home' => false
            ],
            'internal-links' => [
                'title' => __('Internal Links', '1platform-content-ai'),
                'icon' => 'dashicons-admin-links',
                'badge' => null,
                'home' => false
            ],
            'search-console' => [
                'title' => __('Search Console', '1platform-content-ai'),
                'icon' => 'dashicons-cloud',
                'badge' => null,
                'home' => false
            ],
            'publisuites' => [
                'title' => __('Publisuites', '1platform-content-ai'),
                'icon' => 'dashicons-money-alt',
                'badge' => null,
                'home' => false
            ],
            'ads-manager' => [
                'title' => __('Ads Manager', '1platform-content-ai'),
                'icon' => 'dashicons-megaphone',
                'badge' => null,
                'home' => false
            ],
            'analytics' => [
                'title' => __('Google Analytics', '1platform-content-ai'),
                'icon' => 'dashicons-chart-area',
                'badge' => null,
                'home' => false
            ],
        ];
    }

    public function render(): void
    {
?>
        <aside class="contai-sidebar">
            <div class="contai-sidebar-header">
                <div class="contai-sidebar-logo">
                    <span class="dashicons dashicons-admin-tools"></span>
                    <h2><?php esc_html_e('Tools', '1platform-content-ai'); ?></h2>
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
        $parameters = array();
        if(!$item['home']) {
             $parameters = array('section' => $slug);
        }

        $url = add_query_arg(
            $parameters,
            admin_url('admin.php?page=contai-apps')
        );
    ?>
        <li class="contai-sidebar-item <?php echo esc_attr($active_class); ?>">
            <a href="<?php echo esc_url($url); ?>" class="contai-sidebar-link">
                <span class="dashicons <?php echo esc_attr($item['icon']); ?>"></span>
                <span class="contai-sidebar-label"><?php echo esc_html($item['title']); ?></span>
                <?php if ($item['badge'] !== null && $item['badge'] > 0): ?>
                    <span class="contai-sidebar-badge"><?php echo esc_html($item['badge']); ?></span>
                <?php endif; ?>
            </a>
        </li>
<?php
    }
}
