<?php

namespace ContAI\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use WP_Mock;

/**
 * Tests for contai_enqueue_apps_scripts() in includes/admin/admin-apps.php.
 *
 * Validates the section-CSS map dispatch logic and the ads-manager
 * dual-enqueue behavior added to support multiple subsections.
 */
class AdminAppsEnqueueTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        unset($_GET['section']);
        parent::tearDown();
    }

    private function mockScreen(string $screen_id): void
    {
        $screen = new \stdClass();
        $screen->id = $screen_id;
        WP_Mock::userFunction('get_current_screen')->andReturn($screen);
    }

    private function setupCommonWpMocks(): void
    {
        WP_Mock::userFunction('plugin_dir_url')
            ->andReturn('http://example.com/wp-content/plugins/1platform-content-ai/includes/admin/');
        WP_Mock::userFunction('plugin_dir_path')
            ->andReturn('/var/www/wp-content/plugins/1platform-content-ai/');
        WP_Mock::userFunction('contai_get_asset_version')->andReturn('123456789');
    }

    public function test_returns_early_when_not_on_contai_apps_screen(): void
    {
        $this->mockScreen('dashboard');
        WP_Mock::userFunction('wp_enqueue_style')->never();
        WP_Mock::userFunction('wp_enqueue_script')->never();

        \contai_enqueue_apps_scripts();

        $this->addToAssertionCount(1);
    }

    public function test_returns_early_when_screen_is_null(): void
    {
        WP_Mock::userFunction('get_current_screen')->andReturn(null);
        WP_Mock::userFunction('wp_enqueue_style')->never();
        WP_Mock::userFunction('wp_enqueue_script')->never();

        \contai_enqueue_apps_scripts();

        $this->addToAssertionCount(1);
    }

    public function test_internal_links_section_enqueues_internal_links_css(): void
    {
        $_GET['section'] = 'internal-links';
        $this->mockScreen('toplevel_page_contai-apps');
        $this->setupCommonWpMocks();
        WP_Mock::userFunction('sanitize_key')->andReturnArg(0);

        WP_Mock::userFunction('wp_enqueue_style')
            ->once()
            ->with(
                'contai-apps-internal-links',
                \Mockery::pattern('/internal-links\.css$/'),
                ['contai-tokens'],
                \Mockery::any(),
                'all'
            );

        \contai_enqueue_apps_scripts();
        $this->addToAssertionCount(1);
    }

    public function test_search_console_section_enqueues_search_console_css(): void
    {
        $_GET['section'] = 'search-console';
        $this->mockScreen('toplevel_page_contai-apps');
        $this->setupCommonWpMocks();
        WP_Mock::userFunction('sanitize_key')->andReturnArg(0);

        WP_Mock::userFunction('wp_enqueue_style')
            ->once()
            ->with(
                'contai-apps-search-console',
                \Mockery::pattern('/search-console\.css$/'),
                ['contai-tokens'],
                \Mockery::any(),
                'all'
            );

        \contai_enqueue_apps_scripts();
        $this->addToAssertionCount(1);
    }

    public function test_publisuites_section_enqueues_publisuites_css(): void
    {
        $_GET['section'] = 'publisuites';
        $this->mockScreen('toplevel_page_contai-apps');
        $this->setupCommonWpMocks();
        WP_Mock::userFunction('sanitize_key')->andReturnArg(0);

        WP_Mock::userFunction('wp_enqueue_style')
            ->once()
            ->with(
                'contai-apps-publisuites',
                \Mockery::pattern('/publisuites\.css$/'),
                ['contai-tokens'],
                \Mockery::any(),
                'all'
            );

        \contai_enqueue_apps_scripts();
        $this->addToAssertionCount(1);
    }

    public function test_ads_manager_section_enqueues_publisher_panel_css_and_adsense_account_css(): void
    {
        $_GET['section'] = 'ads-manager';
        $this->mockScreen('toplevel_page_contai-apps');
        $this->setupCommonWpMocks();
        WP_Mock::userFunction('sanitize_key')->andReturnArg(0);
        WP_Mock::userFunction('wp_localize_script')->andReturnTrue();
        WP_Mock::userFunction('rest_url')->andReturn('http://example.com/wp-json/contai/v1/adsense/');
        WP_Mock::userFunction('esc_url_raw')->andReturnArg(0);
        WP_Mock::userFunction('wp_create_nonce')->andReturn('test-nonce');

        // Two CSS enqueues expected: publisher-panel + adsense-account
        WP_Mock::userFunction('wp_enqueue_style')->twice();
        // Two JS enqueues expected: publisher-panel.js + adsense-account.js
        WP_Mock::userFunction('wp_enqueue_script')->twice();

        \contai_enqueue_apps_scripts();
        $this->addToAssertionCount(1);
    }

    public function test_unknown_section_does_not_enqueue_anything(): void
    {
        $_GET['section'] = 'nonexistent-section';
        $this->mockScreen('toplevel_page_contai-apps');
        $this->setupCommonWpMocks();
        WP_Mock::userFunction('sanitize_key')->andReturnArg(0);
        WP_Mock::userFunction('wp_enqueue_style')->never();
        WP_Mock::userFunction('wp_enqueue_script')->never();

        \contai_enqueue_apps_scripts();
        $this->addToAssertionCount(1);
    }

    public function test_default_section_is_toc_and_does_not_enqueue(): void
    {
        // No $_GET['section'] set — defaults to 'toc' which is NOT in the map
        $this->mockScreen('toplevel_page_contai-apps');
        $this->setupCommonWpMocks();
        WP_Mock::userFunction('sanitize_key')->andReturnArg(0);
        WP_Mock::userFunction('wp_enqueue_style')->never();
        WP_Mock::userFunction('wp_enqueue_script')->never();

        \contai_enqueue_apps_scripts();
        $this->addToAssertionCount(1);
    }
}
