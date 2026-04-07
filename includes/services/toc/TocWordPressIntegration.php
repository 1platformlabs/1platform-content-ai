<?php

if (!defined('ABSPATH')) exit;

final class ContaiTocWordPressIntegration {

    private ContaiTocGenerator $generator;
    private ContaiTocConfiguration $config;

    public function __construct(ContaiTocGenerator $generator, ContaiTocConfiguration $config) {
        $this->generator = $generator;
        $this->config = $config;
    }

    public function register(): void {
        add_filter('the_content', [$this, 'processContent'], 100);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function processContent(string $content): string {
        if (!$this->shouldProcess()) {
            return $content;
        }

        $result = $this->generator->generate($content);

        return wp_kses_post($result['content']);
    }

    public function enqueueAssets(): void {
        if (!$this->shouldProcess()) {
            return;
        }

        $plugin_url = plugin_dir_url(dirname(dirname(dirname(__FILE__))));
        $css_file = 'includes/services/toc/assets/toc.css';
        $js_file = 'includes/services/toc/assets/toc.js';

        wp_enqueue_style(
            'contai-toc',
            $plugin_url . $css_file,
            [],
            $this->getAssetVersion($css_file)
        );

        if ($this->config->shouldShowToggle() || $this->config->shouldSmoothScroll()) {
            wp_enqueue_script(
                'contai-toc',
                $plugin_url . $js_file,
                [],
                $this->getAssetVersion($js_file),
                true
            );

            wp_localize_script('contai-toc', 'contaiTocConfig', [
                'smoothScroll' => $this->config->shouldSmoothScroll(),
                'smoothScrollOffset' => 30,
            ]);
        }
    }

    private function shouldProcess(): bool {
        if (!$this->config->isEnabled()) {
            return false;
        }

        if (!is_singular()) {
            return false;
        }

        if (is_front_page()) {
            return false;
        }

        if (post_password_required()) {
            return false;
        }

        $post_type = get_post_type();
        $allowed_types = $this->config->getPostTypes();

        return in_array($post_type, $allowed_types, true);
    }

    private function getAssetVersion(string $file): string {
        $plugin_root = dirname(dirname(dirname(dirname(__FILE__))));
        $full_path = $plugin_root . '/' . $file;

        if (file_exists($full_path)) {
            return (string) filemtime($full_path);
        }

        return '1.0.0';
    }
}
