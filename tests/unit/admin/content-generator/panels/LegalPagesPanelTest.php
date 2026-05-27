<?php

namespace ContAI\Tests\Unit\Admin\ContentGenerator\Panels;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiLegalPagesPanel;

class LegalPagesPanelTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void
    {
        unset(
            $_POST['contai_save_legal_info'],
            $_POST['contai_generate_legal_pages'],
            $_POST['contai_save_cookie_settings'],
            $_POST['contai_legal_owner'],
            $_POST['contai_legal_address'],
            $_POST['contai_legal_activity'],
            $_POST['contai_legal_email']
        );
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_renders_neutral_informational_notice_on_fresh_load(): void
    {
        $this->mockLegalOptions(['', '', '', '']);
        $this->stubRenderHelpers();

        $panel = new ContaiLegalPagesPanel();
        $output = $this->captureRender($panel);

        // The persistent warning copy from #129 must NOT be present anymore.
        $this->assertStringNotContainsString('All fields are required to generate legal pages via the API', $output);
        $this->assertStringNotContainsString('contai-notice-warning', $this->extractLegalInfoBody($output));
        $this->assertStringContainsString('All four fields below are required for legal page generation.', $output);
    }

    public function test_save_with_empty_required_field_shows_missing_banner(): void
    {
        $this->mockLegalOptions(['', '', '', '']);
        $this->stubRenderHelpers();
        $this->stubSaveRequest([
            'contai_legal_owner'    => 'Javier Perez',
            'contai_legal_email'    => 'info@example.com',
            'contai_legal_address'  => 'España',
            'contai_legal_activity' => '', // missing
        ]);

        $panel = new ContaiLegalPagesPanel();
        $output = $this->captureRender($panel);

        $this->assertStringContainsString('Missing required information', $output);
        $this->assertStringContainsString('Business Activity', $output);
        $this->assertStringNotContainsString('Legal information saved successfully', $output);
    }

    public function test_save_with_all_fields_shows_success_and_ready_indicator(): void
    {
        // After save, helper should return populated values.
        $this->mockLegalOptions(['Javier', 'info@example.com', 'España', 'Empleo']);
        $this->stubRenderHelpers();
        $this->stubSaveRequest([
            'contai_legal_owner'    => 'Javier',
            'contai_legal_email'    => 'info@example.com',
            'contai_legal_address'  => 'España',
            'contai_legal_activity' => 'Empleo',
        ]);

        $panel = new ContaiLegalPagesPanel();
        $output = $this->captureRender($panel);

        $this->assertStringContainsString('Legal information saved successfully', $output);
        $this->assertStringContainsString('Ready to generate', $output);
    }

    public function test_generate_button_disabled_when_not_ready(): void
    {
        $this->mockLegalOptions(['', '', '', '']);
        $this->stubRenderHelpers();

        $panel = new ContaiLegalPagesPanel();
        $output = $this->captureRender($panel);

        // Disabled HTML attribute should appear on the generate button when fields are missing.
        $generateButtonChunk = $this->extractGenerateButton($output);
        $this->assertStringContainsString('disabled', $generateButtonChunk);
    }

    public function test_generate_button_enabled_when_ready(): void
    {
        $this->mockLegalOptions(['Javier', 'info@example.com', 'España', 'Empleo']);
        $this->stubRenderHelpers();

        $panel = new ContaiLegalPagesPanel();
        $output = $this->captureRender($panel);

        $generateButtonChunk = $this->extractGenerateButton($output);
        $this->assertStringNotContainsString('disabled', $generateButtonChunk);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * @param array{0:string,1:string,2:string,3:string} $values [owner, email, address, activity]
     */
    private function mockLegalOptions(array $values): void
    {
        WP_Mock::userFunction('home_url')->andReturn('https://example.com');
        WP_Mock::userFunction('wp_parse_url')->andReturn('example.com');
        WP_Mock::userFunction('get_option')->with('contai_legal_owner', '')->andReturn($values[0]);
        WP_Mock::userFunction('get_option')->with('contai_legal_email', Mockery::any())->andReturn($values[1]);
        WP_Mock::userFunction('get_option')->with('contai_legal_address', '')->andReturn($values[2]);
        WP_Mock::userFunction('get_option')->with('contai_legal_activity', '')->andReturn($values[3]);
        // Cookie section helpers
        WP_Mock::userFunction('get_option')->with('contai_cookie_notice_enabled')->andReturn('1');
        WP_Mock::userFunction('get_option')->with('contai_site_language', 'spanish')->andReturn('spanish');
        WP_Mock::userFunction('get_option')->with('contai_cookie_notice_text', Mockery::any())->andReturn('');
        WP_Mock::userFunction('get_option')->with('contai_consent_mode', 'opt_out')->andReturn('opt_out');
        WP_Mock::userFunction('get_option')->with('contai_site_theme', '')->andReturn('');
    }

    private function stubSaveRequest(array $fields): void
    {
        $_POST['contai_save_legal_info'] = '1';
        foreach ($fields as $key => $value) {
            $_POST[$key] = $value;
        }

        WP_Mock::userFunction('check_admin_referer')
            ->with('contai_legal_info_nonce', 'contai_legal_info_nonce')
            ->andReturn(1);
        WP_Mock::userFunction('current_user_can')->with('manage_options')->andReturn(true);
        WP_Mock::userFunction('wp_unslash')->andReturnUsing(static fn ($v) => $v);
        WP_Mock::userFunction('sanitize_text_field')->andReturnUsing(static fn ($v) => $v);
        WP_Mock::userFunction('sanitize_email')->andReturnUsing(static fn ($v) => $v);
        WP_Mock::userFunction('is_email')->andReturn(true);
        WP_Mock::userFunction('update_option')->andReturn(true);
    }

    private function stubRenderHelpers(): void
    {
        WP_Mock::userFunction('settings_errors')->andReturn(null);
        WP_Mock::userFunction('wp_nonce_field')->andReturn('');
        WP_Mock::userFunction('esc_html_e')->andReturnUsing(static function ($text) {
            echo $text;
        });
        WP_Mock::userFunction('esc_html__')->andReturnUsing(static fn ($text) => $text);
        WP_Mock::userFunction('esc_attr_e')->andReturnUsing(static function ($text) {
            echo $text;
        });
        WP_Mock::userFunction('esc_html')->andReturnUsing(static fn ($text) => $text);
        WP_Mock::userFunction('esc_attr')->andReturnUsing(static fn ($text) => $text);
        WP_Mock::userFunction('esc_textarea')->andReturnUsing(static fn ($text) => $text);
        WP_Mock::userFunction('checked')->andReturn('');
        WP_Mock::userFunction('selected')->andReturn('');
        WP_Mock::userFunction('disabled')->andReturnUsing(static function ($cond) {
            if ($cond) {
                echo ' disabled';
            }
        });
        WP_Mock::userFunction('__')->andReturnUsing(static fn ($text) => $text);
    }

    private function captureRender(ContaiLegalPagesPanel $panel): string
    {
        ob_start();
        $panel->render();
        return ob_get_clean();
    }

    private function extractLegalInfoBody(string $output): string
    {
        $start = strpos($output, 'contai-panel-legal-info');
        $end   = strpos($output, 'contai-panel-generate-pages');
        if ($start === false || $end === false) {
            return $output;
        }
        return substr($output, $start, $end - $start);
    }

    private function extractGenerateButton(string $output): string
    {
        $start = strpos($output, 'contai_generate_legal_pages');
        if ($start === false) {
            return '';
        }
        $btnStart = strrpos(substr($output, 0, $start), '<button');
        $btnEnd   = strpos($output, '</button>', $start);
        return substr($output, $btnStart, $btnEnd - $btnStart);
    }
}
