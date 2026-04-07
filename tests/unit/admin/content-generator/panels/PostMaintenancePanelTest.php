<?php

namespace ContAI\Tests\Unit\Admin\ContentGenerator\Panels;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiPostMaintenancePanel;

class PostMaintenancePanelTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void
    {
        unset(
            $_POST['contai_randomize_dates'],
            $_POST['contai_update_thumbnails'],
            $_POST['contai_maintenance_nonce'],
            $_REQUEST['contai_maintenance_nonce'],
            $_REQUEST['_wp_http_referer']
        );
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // ── Randomize Dates ────────────────────────────────────────────

    public function test_randomize_dates_success_with_multiple_posts(): void
    {
        $this->simulatePostSubmission('contai_randomize_dates');
        $this->mockNonceAndCapability();
        $this->mockWpdbWithPosts([1, 2, 3]);
        $this->mockDateFunctions();

        $panel = new ContaiPostMaintenancePanel();
        $output = $this->captureRender($panel);

        $this->assertStringContainsString('notice-success', $output);
        $this->assertStringContainsString('3 posts', $output);
    }

    public function test_randomize_dates_error_when_no_posts(): void
    {
        $this->simulatePostSubmission('contai_randomize_dates');
        $this->mockNonceAndCapability();
        $this->mockWpdbWithPosts([]);

        $panel = new ContaiPostMaintenancePanel();
        $output = $this->captureRender($panel);

        $this->assertStringContainsString('notice-error', $output);
        $this->assertStringContainsString('No published posts', $output);
    }

    public function test_randomize_dates_calls_clean_post_cache_per_post(): void
    {
        $this->simulatePostSubmission('contai_randomize_dates');
        $this->mockNonceAndCapability();
        $this->mockWpdbWithPosts([5, 10]);

        WP_Mock::userFunction('wp_rand')->andReturn(0);
        WP_Mock::userFunction('wp_date')->andReturn('2026-01-15 10:30:00');
        WP_Mock::userFunction('get_gmt_from_date')->andReturn('2026-01-15 16:30:00');

        WP_Mock::userFunction('clean_post_cache')
            ->times(2);

        $panel = new ContaiPostMaintenancePanel();
        $output = $this->captureRender($panel);

        $this->assertStringContainsString('notice-success', $output);
    }

    public function test_randomize_dates_does_not_run_without_capability(): void
    {
        $this->simulatePostSubmission('contai_randomize_dates');

        WP_Mock::userFunction('check_admin_referer')
            ->with('contai_post_maintenance', 'contai_maintenance_nonce')
            ->andReturn(1);
        WP_Mock::userFunction('current_user_can')
            ->with('manage_options')
            ->andReturn(false);

        $panel = new ContaiPostMaintenancePanel();
        $output = $this->captureRender($panel);

        $this->assertStringNotContainsString('notice-success', $output);
        $this->assertStringNotContainsString('notice-error', $output);
    }

    // ── Update Thumbnails ──────────────────────────────────────────

    public function test_update_thumbnails_success(): void
    {
        $this->simulatePostSubmission('contai_update_thumbnails');
        $this->mockNonceAndCapability();
        $this->mockWpdbForThumbnails([10]);

        WP_Mock::userFunction('get_post_field')
            ->with('post_content', 10)
            ->andReturn('<p><img src="https://example.com/image.jpg"></p>');
        WP_Mock::userFunction('esc_url_raw')
            ->andReturnUsing(function ($url) { return $url; });
        WP_Mock::userFunction('wp_http_validate_url')
            ->andReturn(true);
        WP_Mock::userFunction('media_sideload_image')
            ->andReturn(99);
        WP_Mock::userFunction('is_wp_error')
            ->with(99)
            ->andReturn(false);
        WP_Mock::userFunction('set_post_thumbnail')
            ->once()
            ->with(10, 99);

        $panel = new ContaiPostMaintenancePanel();
        $output = $this->captureRender($panel);

        $this->assertStringContainsString('notice-success', $output);
        $this->assertStringContainsString('1 posts', $output);
    }

    public function test_update_thumbnails_error_when_no_posts(): void
    {
        $this->simulatePostSubmission('contai_update_thumbnails');
        $this->mockNonceAndCapability();
        $this->mockWpdbForThumbnails([]);

        $panel = new ContaiPostMaintenancePanel();
        $output = $this->captureRender($panel);

        $this->assertStringContainsString('notice-error', $output);
        $this->assertStringContainsString('No published posts', $output);
    }

    public function test_update_thumbnails_skips_post_with_empty_content(): void
    {
        $this->simulatePostSubmission('contai_update_thumbnails');
        $this->mockNonceAndCapability();
        $this->mockWpdbForThumbnails([20]);

        WP_Mock::userFunction('get_post_field')
            ->with('post_content', 20)
            ->andReturn('');

        $panel = new ContaiPostMaintenancePanel();
        $output = $this->captureRender($panel);

        $this->assertStringContainsString('notice-success', $output);
        $this->assertStringContainsString('0 posts', $output);
    }

    public function test_update_thumbnails_skips_post_with_no_images(): void
    {
        $this->simulatePostSubmission('contai_update_thumbnails');
        $this->mockNonceAndCapability();
        $this->mockWpdbForThumbnails([30]);

        WP_Mock::userFunction('get_post_field')
            ->with('post_content', 30)
            ->andReturn('<p>Plain text without images</p>');

        $panel = new ContaiPostMaintenancePanel();
        $output = $this->captureRender($panel);

        $this->assertStringContainsString('notice-success', $output);
        $this->assertStringContainsString('0 posts', $output);
    }

    public function test_update_thumbnails_skips_post_when_sideload_fails(): void
    {
        $this->simulatePostSubmission('contai_update_thumbnails');
        $this->mockNonceAndCapability();
        $this->mockWpdbForThumbnails([40]);

        WP_Mock::userFunction('get_post_field')
            ->with('post_content', 40)
            ->andReturn('<img src="https://example.com/broken.jpg">');
        WP_Mock::userFunction('esc_url_raw')
            ->andReturnUsing(function ($url) { return $url; });
        WP_Mock::userFunction('wp_http_validate_url')
            ->andReturn(true);

        $wp_error = Mockery::mock('WP_Error');
        WP_Mock::userFunction('media_sideload_image')
            ->andReturn($wp_error);
        WP_Mock::userFunction('is_wp_error')
            ->with($wp_error)
            ->andReturn(true);

        $panel = new ContaiPostMaintenancePanel();
        $output = $this->captureRender($panel);

        $this->assertStringContainsString('notice-success', $output);
        $this->assertStringContainsString('0 posts', $output);
    }

    public function test_update_thumbnails_skips_invalid_url(): void
    {
        $this->simulatePostSubmission('contai_update_thumbnails');
        $this->mockNonceAndCapability();
        $this->mockWpdbForThumbnails([50]);

        WP_Mock::userFunction('get_post_field')
            ->with('post_content', 50)
            ->andReturn('<img src="javascript:alert(1)">');
        WP_Mock::userFunction('esc_url_raw')
            ->andReturn('');

        $panel = new ContaiPostMaintenancePanel();
        $output = $this->captureRender($panel);

        $this->assertStringContainsString('notice-success', $output);
        $this->assertStringContainsString('0 posts', $output);
    }

    public function test_update_thumbnails_does_not_run_without_capability(): void
    {
        $this->simulatePostSubmission('contai_update_thumbnails');

        WP_Mock::userFunction('check_admin_referer')
            ->with('contai_post_maintenance', 'contai_maintenance_nonce')
            ->andReturn(1);
        WP_Mock::userFunction('current_user_can')
            ->with('manage_options')
            ->andReturn(false);

        $panel = new ContaiPostMaintenancePanel();
        $output = $this->captureRender($panel);

        $this->assertStringNotContainsString('notice-success', $output);
        $this->assertStringNotContainsString('notice-error', $output);
    }

    // ── No Action ──────────────────────────────────────────────────

    public function test_no_action_when_no_post_data(): void
    {
        $panel = new ContaiPostMaintenancePanel();
        $output = $this->captureRender($panel);

        $this->assertStringNotContainsString('notice-success', $output);
        $this->assertStringNotContainsString('notice-error', $output);
    }

    // ── Render Structure ────────────────────────────────────────────

    public function test_notices_render_before_forms(): void
    {
        $this->simulatePostSubmission('contai_randomize_dates');
        $this->mockNonceAndCapability();
        $this->mockWpdbWithPosts([1]);
        $this->mockDateFunctions();

        $panel = new ContaiPostMaintenancePanel();
        $output = $this->captureRender($panel);

        $noticePos = strpos($output, 'notice-success');
        $formPos = strpos($output, 'contai-maintenance-form');

        $this->assertNotFalse($noticePos);
        $this->assertNotFalse($formPos);
        $this->assertLessThan($formPos, $noticePos, 'Notices must render before forms');
    }

    public function test_render_contains_both_action_buttons(): void
    {
        $panel = new ContaiPostMaintenancePanel();
        $output = $this->captureRender($panel);

        $this->assertStringContainsString('contai_randomize_dates', $output);
        $this->assertStringContainsString('contai_update_thumbnails', $output);
        $this->assertStringContainsString('contai-maintenance-form', $output);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function simulatePostSubmission(string $action): void
    {
        $_POST[$action] = '';
        $_POST['contai_maintenance_nonce'] = 'test_nonce';
        $_REQUEST['contai_maintenance_nonce'] = 'test_nonce';
        $_REQUEST['_wp_http_referer'] = 'http://example.com/wp-admin/admin.php';
    }

    private function mockNonceAndCapability(): void
    {
        WP_Mock::userFunction('check_admin_referer')
            ->with('contai_post_maintenance', 'contai_maintenance_nonce')
            ->andReturn(1);
        WP_Mock::userFunction('current_user_can')
            ->with('manage_options')
            ->andReturn(true);
    }

    private function mockWpdbWithPosts(array $postIds): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('get_col')->andReturn($postIds);

        if (!empty($postIds)) {
            $wpdb->shouldReceive('update')->andReturn(1);
        }
    }

    private function mockWpdbForThumbnails(array $postIds): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('get_col')->andReturn($postIds);
    }

    private function mockDateFunctions(): void
    {
        WP_Mock::userFunction('wp_rand')->andReturn(0);
        WP_Mock::userFunction('wp_date')->andReturn('2026-01-15 10:30:00');
        WP_Mock::userFunction('get_gmt_from_date')->andReturn('2026-01-15 16:30:00');
        WP_Mock::userFunction('clean_post_cache')->andReturn(null);
    }

    private function captureRender(ContaiPostMaintenancePanel $panel): string
    {
        WP_Mock::userFunction('wp_nonce_field')->andReturn('');
        WP_Mock::userFunction('esc_html_e')->andReturnUsing(function ($text) {
            echo $text;
        });
        WP_Mock::userFunction('esc_html__')->andReturnUsing(function ($text) {
            return $text;
        });
        WP_Mock::userFunction('wp_kses_post')->andReturnUsing(function ($text) {
            return $text;
        });

        ob_start();
        $panel->render();
        return ob_get_clean();
    }
}
