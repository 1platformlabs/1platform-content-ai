<?php

namespace ContAI\Tests\Unit\Admin\SiteGenerator;

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the Site Wizard form submission handler.
 *
 * Validates the fix for GitHub issue #54: "Launch Site Generation"
 * refreshed the page silently without executing any action or
 * showing error/success feedback.
 *
 * Root cause: The handler had no try/catch, no error logging, used
 * wp_die() for nonce failures (which is swallowed on production sites
 * with display_errors off), and relied on URL parameters for error
 * messages (fragile when wp_safe_redirect fails due to headers already sent).
 *
 * Fix: Switched to transient-based notices, graceful nonce handling,
 * explicit form action, and try/catch around the processing logic.
 */
class SiteGeneratorSubmissionTest extends TestCase
{
    private string $handlerFile;
    private string $formFile;

    public function setUp(): void
    {
        parent::setUp();
        $this->handlerFile = dirname(__DIR__, 4) . '/includes/admin/admin-ai-site-generator.php';
        $this->formFile = dirname(__DIR__, 4) . '/includes/admin/ai-site-generator/site-generator-form.php';
    }

    public function test_form_has_explicit_action_attribute(): void
    {
        $this->assertFileExists($this->formFile);
        $content = file_get_contents($this->formFile);

        $this->assertStringContainsString(
            'action="<?php echo esc_url( admin_url(',
            $content,
            'Form must have an explicit action attribute with admin_url() to prevent URL resolution issues (#54)'
        );
    }

    public function test_handler_uses_transient_notices_not_url_params(): void
    {
        $content = file_get_contents($this->handlerFile);

        $this->assertStringContainsString(
            "set_transient( 'contai_site_gen_notice'",
            $content,
            'Handler must use transient-based notices for reliable message delivery (#54)'
        );
    }

    public function test_handler_has_try_catch_around_processing(): void
    {
        $content = file_get_contents($this->handlerFile);

        $this->assertStringContainsString(
            'catch ( \\Throwable $e )',
            $content,
            'Handler must catch Throwable to prevent silent failures on production (#54)'
        );
    }

    public function test_handler_uses_graceful_nonce_verification(): void
    {
        $content = file_get_contents($this->handlerFile);

        // Must use wp_verify_nonce (returns false) instead of check_admin_referer (calls wp_die)
        $this->assertStringContainsString(
            'wp_verify_nonce(',
            $content,
            'Handler must use wp_verify_nonce() for graceful nonce verification (#54)'
        );

        // check_admin_referer should NOT be used (it wp_die()s on failure)
        $this->assertStringNotContainsString(
            'check_admin_referer(',
            $content,
            'Handler must NOT use check_admin_referer() which wp_die()s silently (#54)'
        );
    }

    public function test_page_render_reads_transient_notices(): void
    {
        $content = file_get_contents($this->handlerFile);

        $this->assertStringContainsString(
            "get_transient( 'contai_site_gen_notice' )",
            $content,
            'Page render must read transient notices for display (#54)'
        );

        $this->assertStringContainsString(
            "delete_transient( 'contai_site_gen_notice' )",
            $content,
            'Page render must delete transient after display to prevent stale notices (#54)'
        );
    }

    public function test_handler_logs_errors_on_failure(): void
    {
        $content = file_get_contents($this->handlerFile);

        $this->assertStringContainsString(
            'contai_log(',
            $content,
            'Handler must log errors when processing fails for debugging (#54)'
        );
    }

    public function test_no_url_param_based_error_messages_in_handler(): void
    {
        $content = file_get_contents($this->handlerFile);

        // The old pattern of passing error messages via URL params is fragile
        $this->assertStringNotContainsString(
            "'error' => 1",
            $content,
            'Handler should not use URL params for error messages — use transients (#54)'
        );
    }
}
