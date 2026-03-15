<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../../database/repositories/KeywordRepository.php';

class ContaiContentGeneratorSidebar {

    private string $current_section;
    private array $menu_items;
    private ContaiKeywordRepository $keywordRepository;

    public function __construct(string $current_section = 'keyword-extractor') {
        $this->current_section = $current_section;
        $this->keywordRepository = new ContaiKeywordRepository();
        $this->init_menu_items();
    }

    private function init_menu_items(): void {
        $this->menu_items = [
            'keyword-extractor' => [
                'title' => __('Keyword Extractor', '1platform-content-ai'),
                'icon' => 'dashicons-search',
                'badge' => null,
            ],
            'post-generator' => [
                'title' => __('Post Generator', '1platform-content-ai'),
                'icon' => 'dashicons-edit',
                'badge' => null,
            ],
            'keywords-list' => [
                'title' => __('Keywords List', '1platform-content-ai'),
                'icon' => 'dashicons-list-view',
                'badge' => $this->get_keywords_count(),
            ],
            'post-maintenance' => [
                'title' => __('Post Maintenance', '1platform-content-ai'),
                'icon' => 'dashicons-admin-tools',
                'badge' => $this->get_posts_count(),
            ],
            'generate-comments' => [
                'title' => __('Generate Comments', '1platform-content-ai'),
                'icon' => 'dashicons-admin-comments',
                'badge' => null,
            ],
            'legal-pages' => [
                'title' => __('Legal Pages', '1platform-content-ai'),
                'icon' => 'dashicons-media-text',
                'badge' => null,
            ],
        ];
    }

    private function get_keywords_count(): int {
        return $this->keywordRepository->count();
    }

    private function get_posts_count(): int {
        $count = wp_count_posts('post');
        return (int) ($count->publish ?? 0);
    }

    public function render(): void {
        ?>
        <aside class="contai-sidebar">
            <div class="contai-sidebar-header">
                <div class="contai-sidebar-logo">
                    <span class="dashicons dashicons-edit-large"></span>
                    <h2><?php esc_html_e('Content', '1platform-content-ai'); ?></h2>
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

    private function render_menu_item(string $slug, array $item): void {
        $is_active = $this->current_section === $slug;
        $active_class = $is_active ? 'active' : '';
        $url = add_query_arg('section', $slug, admin_url('admin.php?page=contai-content-generator'));
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
