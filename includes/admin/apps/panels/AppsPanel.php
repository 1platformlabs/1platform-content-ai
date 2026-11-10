<?php

if (!defined('ABSPATH')) exit;

class ContaiAppsPanel {

    private array $available_apps;

    public function __construct() {
        $this->init_available_apps();
    }

    private function init_available_apps(): void {
        $rows = [
            'toc_settings'   => ['toc', 'list-view', __('Table of Contents', '1platform-content-ai'), __('Automatically generate table of contents for your posts with customizable settings', '1platform-content-ai')],
            'internal_links' => ['internal-links', 'admin-links', __('Internal Links', '1platform-content-ai'), __('Automatically create internal links between posts based on keywords for better SEO and navigation', '1platform-content-ai')],
            'search_console' => ['search-console', 'cloud', __('Search Console', '1platform-content-ai'), __('Connect your website to Google Search Console to submit sitemaps and verify your site ownership', '1platform-content-ai')],
            'publisuites'    => ['publisuites', 'money-alt', __('Link Building', '1platform-content-ai'), __('Connect your website to the marketplace to monetize your content and manage sponsored posts', '1platform-content-ai')],
            'ads_manager'    => ['ads-manager', 'megaphone', __('Ads Manager', '1platform-content-ai'), __('Configure AdSense publisher IDs, ads.txt generation, and custom header code injection', '1platform-content-ai')],
            'analytics'      => ['analytics', 'chart-area', __('Google Analytics', '1platform-content-ai'), __('Connect Google Analytics GA4 to track AI content performance, measure ROI, and monitor site traffic', '1platform-content-ai')],
        ];

        $this->available_apps = [];
        foreach ($rows as $slug => [$section, $icon, $title, $description]) {
            $this->available_apps[$slug] = [
                'title'       => $title,
                'description' => $description,
                'icon'        => 'dashicons-' . $icon,
                'url'         => add_query_arg(['section' => $section], admin_url('admin.php?page=contai-apps')),
                'status'      => 'active',
            ];
        }
    }

    public function render(): void {
        ?>
        <div class="contai-settings-panel contai-panel-toc">
            <div class="contai-panel-body">
                <div class="contai-toc-intro">
                    <p class="contai-intro-text">
                        <?php esc_html_e('Welcome to the Tools section. Below you will find all available tools and integrations.', '1platform-content-ai'); ?>
                    </p>
                </div>

                <div class="contai-apps-grid">
                    <?php foreach ($this->available_apps as $slug => $app): ?>
                        <?php $this->render_app_card($slug, $app); ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_app_card(string $slug, array $app): void {
        $status_class = $app['status'] === 'active' ? 'active' : 'inactive';
        ?>
        <div class="contai-app-card <?php echo esc_attr($status_class); ?>">
            <div class="contai-app-card-icon">
                <span class="dashicons <?php echo esc_attr($app['icon']); ?>"></span>
            </div>
            <div class="contai-app-card-content">
                <h3 class="contai-app-card-title"><?php echo esc_html($app['title']); ?></h3>
                <p class="contai-app-card-description"><?php echo esc_html($app['description']); ?></p>
            </div>
            <div class="contai-app-card-footer">
                <?php if ($app['status'] === 'active'): ?>
                    <a href="<?php echo esc_url($app['url']); ?>" class="button button-primary">
                        <?php esc_html_e('Open', '1platform-content-ai'); ?>
                    </a>
                <?php else: ?>
                    <span class="contai-app-status-badge">
                        <?php esc_html_e('Coming Soon', '1platform-content-ai'); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
