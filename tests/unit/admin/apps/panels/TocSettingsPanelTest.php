<?php

namespace ContAI\Tests\Unit\Admin\Apps\Panels;

use WP_Mock;
use PHPUnit\Framework\TestCase;
use TocSettingsPanel;
use TocConfiguration;

class TocSettingsPanelTest extends TestCase {

    private TocConfiguration $config;
    private TocSettingsPanel $panel;

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();

        $this->config = new TocConfiguration();
        $this->panel = new TocSettingsPanel($this->config);
    }

    public function tearDown(): void {
        unset(
            $_SERVER['REQUEST_METHOD'],
            $_POST['toc_settings_nonce'],
            $_POST['reset_settings'],
            $_POST['theme'],
            $_POST['enabled'],
            $_POST['post_types'],
            $_POST['heading_levels'],
            $_POST['min_headings'],
            $_POST['position'],
            $_POST['title'],
            $_POST['show_title'],
            $_POST['show_toggle'],
            $_POST['initial_state'],
            $_POST['show_hierarchy'],
            $_POST['numbered_list'],
            $_POST['exclude_patterns'],
            $_POST['lowercase_anchors'],
            $_POST['hyphenate_anchors'],
            $_POST['smooth_scroll']
        );
        WP_Mock::tearDown();
        parent::tearDown();
    }

    // ── handleSave: successful save ────────────────────────────────

    public function test_handle_save_calls_update_with_theme_black(): void {
        $this->simulatePostRequest(['theme' => 'black']);
        $this->mockSecurityChecks();
        $this->mockSanitizationFunctions();

        WP_Mock::userFunction('get_option')
            ->andReturn($this->buildConfig(['theme' => 'light-blue']));

        WP_Mock::userFunction('get_post_types')
            ->with(['public' => true], 'names')
            ->andReturn(['post' => 'post', 'page' => 'page']);

        WP_Mock::userFunction('update_option')
            ->once()
            ->with('contai_toc_config', \Mockery::on(function ($data) {
                return is_array($data) && $data['theme'] === 'black';
            }))
            ->andReturn(true);

        // purgePageCaches calls has_action for LiteSpeed and Cachify
        WP_Mock::userFunction('has_action')->andReturn(false);

        $this->mockNoticeOutput();
        $this->mockRenderDependencies();

        ob_start();
        $this->panel->render();
        ob_end_clean();

        // If we got here without error, the save + purge flow executed correctly
        $this->addToAssertionCount(1);
    }

    public function test_handle_save_shows_error_on_failed_update(): void {
        $this->simulatePostRequest(['theme' => 'black']);
        $this->mockSecurityChecks();
        $this->mockSanitizationFunctions();

        WP_Mock::userFunction('get_option')
            ->andReturn($this->buildConfig(['theme' => 'light-blue']));

        WP_Mock::userFunction('get_post_types')
            ->with(['public' => true], 'names')
            ->andReturn(['post' => 'post', 'page' => 'page']);

        WP_Mock::userFunction('update_option')
            ->once()
            ->andReturn(false);

        WP_Mock::userFunction('__')->andReturnArg(0);
        WP_Mock::userFunction('add_settings_error')
            ->once()
            ->with('toc_settings', 'toc_settings_message', \Mockery::type('string'), 'error');
        WP_Mock::userFunction('settings_errors');

        $this->mockRenderDependencies();

        ob_start();
        $this->panel->render();
        ob_end_clean();

        $this->addToAssertionCount(1);
    }

    // ── handleSave: reset ──────────────────────────────────────────

    public function test_handle_reset_calls_reset_and_purges_caches(): void {
        $this->simulatePostRequest();
        $_POST['reset_settings'] = '1';

        $this->mockSecurityChecks();

        WP_Mock::userFunction('get_option')->andReturn($this->buildConfig());
        WP_Mock::userFunction('get_post_types')
            ->with(['public' => true], 'names')
            ->andReturn(['post' => 'post', 'page' => 'page']);

        WP_Mock::userFunction('update_option')
            ->once()
            ->with('contai_toc_config', \Mockery::type('array'))
            ->andReturn(true);

        WP_Mock::userFunction('has_action')->andReturn(false);

        $this->mockNoticeOutput();
        $this->mockRenderDependencies();

        ob_start();
        $this->panel->render();
        ob_end_clean();

        $this->addToAssertionCount(1);
    }

    // ── handleSave: theme field variations ─────────────────────────

    public function test_handle_save_defaults_theme_to_grey_when_missing(): void {
        $this->simulatePostRequest();
        unset($_POST['theme']);

        $this->mockSecurityChecks();
        $this->mockSanitizationFunctions();

        WP_Mock::userFunction('get_option')
            ->andReturn($this->buildConfig(['theme' => 'black']));

        WP_Mock::userFunction('get_post_types')
            ->with(['public' => true], 'names')
            ->andReturn(['post' => 'post', 'page' => 'page']);

        WP_Mock::userFunction('update_option')
            ->once()
            ->with('contai_toc_config', \Mockery::on(function ($data) {
                return $data['theme'] === 'grey';
            }))
            ->andReturn(true);

        WP_Mock::userFunction('has_action')->andReturn(false);

        $this->mockNoticeOutput();
        $this->mockRenderDependencies();

        ob_start();
        $this->panel->render();
        ob_end_clean();

        $this->addToAssertionCount(1);
    }

    // ── purgePageCaches: LiteSpeed hook ────────────────────────────

    public function test_purge_fires_litespeed_action_when_registered(): void {
        $this->simulatePostRequest(['theme' => 'black']);
        $this->mockSecurityChecks();
        $this->mockSanitizationFunctions();

        WP_Mock::userFunction('get_option')
            ->andReturn($this->buildConfig(['theme' => 'grey']));

        WP_Mock::userFunction('get_post_types')
            ->with(['public' => true], 'names')
            ->andReturn(['post' => 'post', 'page' => 'page']);

        WP_Mock::userFunction('update_option')->andReturn(true);

        WP_Mock::userFunction('has_action')
            ->with('litespeed_purge_all')
            ->andReturn(true);
        WP_Mock::expectAction('litespeed_purge_all');

        WP_Mock::userFunction('has_action')
            ->with('cachify_flush_cache')
            ->andReturn(false);

        $this->mockNoticeOutput();
        $this->mockRenderDependencies();

        ob_start();
        $this->panel->render();
        ob_end_clean();

        $this->addToAssertionCount(1);
    }

    // ── render: no POST → no save ──────────────────────────────────

    public function test_render_without_post_does_not_save(): void {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        WP_Mock::userFunction('get_option')
            ->andReturn($this->buildConfig());

        WP_Mock::userFunction('update_option')->never();

        $this->mockRenderDependencies();

        ob_start();
        $this->panel->render();
        ob_end_clean();

        $this->addToAssertionCount(1);
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function simulatePostRequest(array $overrides = []): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['toc_settings_nonce'] = 'valid_nonce';
        $_POST['enabled'] = '1';
        $_POST['post_types'] = ['post'];
        $_POST['heading_levels'] = [2, 3];
        $_POST['min_headings'] = '4';
        $_POST['position'] = 'before_first_heading';
        $_POST['title'] = 'Table of Contents';
        $_POST['show_title'] = '1';
        $_POST['show_toggle'] = '1';
        $_POST['initial_state'] = 'show';
        $_POST['show_hierarchy'] = '1';
        $_POST['numbered_list'] = '1';
        $_POST['exclude_patterns'] = '';
        $_POST['theme'] = $overrides['theme'] ?? 'grey';
        $_POST['lowercase_anchors'] = '1';
        $_POST['hyphenate_anchors'] = '1';
        $_POST['smooth_scroll'] = '1';
    }

    private function buildConfig(array $overrides = []): array {
        return array_merge([
            'enabled' => true,
            'post_types' => ['post', 'page'],
            'heading_levels' => [2, 3, 4],
            'min_headings' => 4,
            'position' => 'before_first_heading',
            'title' => 'Table of Contents',
            'show_title' => true,
            'show_toggle' => true,
            'initial_state' => 'show',
            'show_hierarchy' => true,
            'numbered_list' => true,
            'exclude_patterns' => [],
            'theme' => 'grey',
            'lowercase_anchors' => true,
            'hyphenate_anchors' => true,
            'smooth_scroll' => true,
        ], $overrides);
    }

    private function mockSecurityChecks(): void {
        WP_Mock::userFunction('sanitize_text_field')->andReturnArg(0);
        WP_Mock::userFunction('wp_unslash')->andReturnArg(0);
        WP_Mock::userFunction('wp_verify_nonce')->andReturn(true);
        WP_Mock::userFunction('check_admin_referer')->andReturn(true);
        WP_Mock::userFunction('current_user_can')->andReturn(true);
    }

    private function mockSanitizationFunctions(): void {
        WP_Mock::userFunction('sanitize_textarea_field')->andReturnArg(0);
    }

    private function mockNoticeOutput(): void {
        WP_Mock::userFunction('__')->andReturnArg(0);
        WP_Mock::userFunction('add_settings_error');
        WP_Mock::userFunction('settings_errors');
    }

    private function mockRenderDependencies(): void {
        WP_Mock::userFunction('wp_nonce_field');
        WP_Mock::userFunction('checked');
        WP_Mock::userFunction('selected');
        WP_Mock::userFunction('esc_attr')->andReturnArg(0);
        WP_Mock::userFunction('esc_html')->andReturnArg(0);
        WP_Mock::userFunction('esc_html__')->andReturnArg(0);
        WP_Mock::userFunction('esc_html_e');
        WP_Mock::userFunction('esc_attr__')->andReturnArg(0);
        WP_Mock::userFunction('esc_attr_e');
        WP_Mock::userFunction('esc_textarea')->andReturnArg(0);
        WP_Mock::userFunction('__')->andReturnArg(0);
        WP_Mock::userFunction('get_post_types')
            ->with(['public' => true], 'objects')
            ->andReturn([]);
    }
}
