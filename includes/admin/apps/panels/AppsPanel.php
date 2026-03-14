<?php

if (!defined('ABSPATH')) exit;

class ContaiAppsPanel {

    private array $available_apps;

    public function __construct() {
        $this->init_available_apps();
    }

    private function init_available_apps(): void {
        $this->available_apps = [
            'toc_settings' => [
                'title' => __('Table of Contents', '1platform-content-ai'),
                'description' => __('Automatically generate table of contents for your posts with customizable settings', '1platform-content-ai'),
                'icon' => 'dashicons-list-view',
                'url' => add_query_arg(['section' => 'toc'], admin_url('admin.php?page=contai-apps')),
                'status' => 'active',
            ],
            'internal_links' => [
                'title' => __('Internal Links', '1platform-content-ai'),
                'description' => __('Automatically create internal links between posts based on keywords for better SEO and navigation', '1platform-content-ai'),
                'icon' => 'dashicons-admin-links',
                'url' => add_query_arg(['section' => 'internal-links'], admin_url('admin.php?page=contai-apps')),
                'status' => 'active',
            ],
            'search_console' => [
                'title' => __('Search Console', '1platform-content-ai'),
                'description' => __('Connect your website to Google Search Console to submit sitemaps and verify your site ownership', '1platform-content-ai'),
                'icon' => 'dashicons-cloud',
                'url' => add_query_arg(['section' => 'search-console'], admin_url('admin.php?page=contai-apps')),
                'status' => 'active',
            ],
            'publisuites' => [
                'title' => __('Publisuites', '1platform-content-ai'),
                'description' => __('Connect your website to Publisuites to monetize your content and manage sponsored posts', '1platform-content-ai'),
                'icon' => 'dashicons-money-alt',
                'url' => add_query_arg(['section' => 'publisuites'], admin_url('admin.php?page=contai-apps')),
                'status' => 'active',
            ],
            'ads_manager' => [
                'title' => __('Ads Manager', '1platform-content-ai'),
                'description' => __('Configure AdSense publisher IDs, ads.txt generation, and custom header code injection', '1platform-content-ai'),
                'icon' => 'dashicons-megaphone',
                'url' => add_query_arg(['section' => 'ads-manager'], admin_url('admin.php?page=contai-apps')),
                'status' => 'active',
            ],
        ];
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
