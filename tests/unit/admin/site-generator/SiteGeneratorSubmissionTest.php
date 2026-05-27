<?php

namespace ContAI\Tests\Unit\Admin\SiteGenerator;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the Site Wizard form submission handler and form rendering.
 *
 * Covers GitHub issue #54: "Launch Site Generation" refreshed the page
 * silently without executing any action or showing error/success feedback.
 *
 * Current contract:
 * - All submission paths (nonce failure, validation error, success,
 *   uncaught exception) use a Post-Redirect-Get redirect so that the
 *   refresh button can no longer re-submit the form and any notice
 *   survives the redirect via a per-user transient.
 * - On error paths the submitted form values are stashed alongside the
 *   notice so the wizard re-renders pre-filled.
 * - The capability check is the only path that wp_die()s.
 */
class SiteGeneratorSubmissionTest extends TestCase
{
    private string $handlerFile;
    private string $formFile;
    private string $handlerContent;
    private string $formContent;

    public function setUp(): void
    {
        parent::setUp();
        $this->handlerFile    = dirname(__DIR__, 4) . '/includes/admin/admin-ai-site-generator.php';
        $this->formFile       = dirname(__DIR__, 4) . '/includes/admin/ai-site-generator/site-generator-form.php';
        $this->handlerContent = file_get_contents($this->handlerFile);
        $this->formContent    = file_get_contents($this->formFile);
    }

    // ── Handler: Entry Guard ───────────────────────────────────────

    public function test_handler_returns_early_when_no_post_data(): void
    {
        $this->assertStringContainsString(
            "if ( ! isset( \$_POST['contai_start_site_generation'] ) )",
            $this->handlerContent,
            'Handler must check for POST submission marker before processing'
        );

        $earlyReturn = $this->extractBlock($this->handlerContent, "isset( \$_POST['contai_start_site_generation']", 'return;');
        $this->assertNotNull($earlyReturn, 'Early return must follow POST check');
        $this->assertStringNotContainsString('wp_safe_redirect', $earlyReturn, 'Early return must not redirect');
        $this->assertStringNotContainsString('set_transient', $earlyReturn, 'Early return must not stash a transient');
    }

    // ── Handler: Nonce Verification ────────────────────────────────

    public function test_handler_uses_graceful_nonce_verification(): void
    {
        $this->assertStringContainsString(
            'wp_verify_nonce(',
            $this->handlerContent,
            'Handler must use wp_verify_nonce() for graceful nonce verification (#54)'
        );

        $this->assertStringNotContainsString(
            'check_admin_referer(',
            $this->handlerContent,
            'Handler must NOT use check_admin_referer() which wp_die()s silently (#54)'
        );
    }

    public function test_nonce_failure_redirects_via_helper_and_preserves_form(): void
    {
        $nonceBlock = $this->extractBlock($this->handlerContent, '! wp_verify_nonce(', '}');
        $this->assertNotNull($nonceBlock, 'Nonce failure block must exist');

        $this->assertStringContainsString(
            'contai_redirect_with_notice(',
            $nonceBlock,
            'Nonce failure must redirect via contai_redirect_with_notice (#54)'
        );

        $this->assertStringContainsString(
            'contai_capture_submitted_form_values()',
            $nonceBlock,
            'Nonce failure must preserve submitted form values across the redirect (#54)'
        );

        $this->assertStringContainsString(
            "'type'    => 'error'",
            $nonceBlock,
            'Nonce failure notice must be of type error'
        );

        $this->assertStringContainsString(
            'session expired',
            $nonceBlock,
            'Nonce failure must surface a human-readable "session expired" message'
        );

        $this->assertStringNotContainsString(
            "GLOBALS['contai_site_gen_inline_notice']",
            $nonceBlock,
            'Nonce failure must no longer rely on a global notice (#54)'
        );
    }

    // ── Handler: Capability Check ──────────────────────────────────

    public function test_handler_checks_manage_options_capability(): void
    {
        $this->assertStringContainsString(
            "current_user_can( 'manage_options' )",
            $this->handlerContent,
            'Handler must verify manage_options capability'
        );

        $this->assertStringContainsString(
            'wp_die(',
            $this->handlerContent,
            'Capability failure must use wp_die()'
        );
    }

    // ── Handler: Try/Catch + Error Return ──────────────────────────

    public function test_handler_has_try_catch_around_processing(): void
    {
        $this->assertStringContainsString(
            'catch ( \\Throwable $e )',
            $this->handlerContent,
            'Handler must catch Throwable to prevent silent failures on production (#54)'
        );
    }

    public function test_handler_redirects_with_validation_error(): void
    {
        $this->assertStringContainsString(
            '$error = contai_process_site_generation_submission()',
            $this->handlerContent,
            'Handler must capture return value from processing function'
        );

        $errorBlock = $this->extractBlock($this->handlerContent, 'if ( $error )', '}');
        $this->assertNotNull($errorBlock, 'Validation error block must exist');

        $this->assertStringContainsString(
            'contai_redirect_with_notice( $error',
            $errorBlock,
            'Handler must redirect with the validation error notice (#54)'
        );
        $this->assertStringContainsString(
            'contai_capture_submitted_form_values()',
            $errorBlock,
            'Handler must preserve submitted form values on validation error (#54)'
        );
    }

    public function test_exception_handler_logs_and_redirects_with_form_values(): void
    {
        $catchBlock = $this->extractBlock($this->handlerContent, 'catch ( \\Throwable $e )', 'contai_capture_submitted_form_values()');
        $this->assertNotNull($catchBlock, 'Catch block must exist');

        $this->assertStringContainsString(
            'contai_log(',
            $catchBlock,
            'Exception handler must log the error for debugging (#54)'
        );

        $this->assertStringContainsString(
            'contai_redirect_with_notice(',
            $catchBlock,
            'Exception handler must redirect with a notice (#54)'
        );
    }

    // ── Helper: Per-user Transient Key ─────────────────────────────

    public function test_transient_key_is_scoped_per_user(): void
    {
        $this->assertStringContainsString(
            'function contai_site_gen_notice_transient_key( int $user_id )',
            $this->handlerContent,
            'Per-user transient key helper must exist (#54)'
        );
        $this->assertStringContainsString(
            "return 'contai_site_gen_notice_' . \$user_id",
            $this->handlerContent,
            'Transient key must be namespaced by user id to avoid cross-user notice leaks (#54)'
        );
    }

    // ── Helper: Form Value Capture ─────────────────────────────────

    public function test_form_value_capture_uses_whitelist(): void
    {
        $this->assertStringContainsString(
            'function contai_site_gen_preserved_fields()',
            $this->handlerContent,
            'Preserved fields whitelist helper must exist'
        );

        foreach ([
            'contai_site_topic',
            'contai_site_category',
            'contai_site_language',
            'contai_legal_owner',
            'contai_legal_email',
            'contai_legal_address',
            'contai_legal_activity',
            'contai_num_posts',
            'contai_comments_per_post',
            'contai_image_provider',
            'contai_adsense_publisher',
        ] as $field) {
            $this->assertStringContainsString(
                "'{$field}'",
                $this->handlerContent,
                "Preserved-field whitelist must include {$field}"
            );
        }
    }

    public function test_form_value_capture_sanitizes_values(): void
    {
        $this->assertStringContainsString(
            'sanitize_text_field( wp_unslash( $_POST[ $field ] ) )',
            $this->handlerContent,
            'Captured POST values must be sanitized before being stashed in the transient (#54)'
        );
    }

    // ── Helper: Redirect-with-notice ───────────────────────────────

    public function test_redirect_with_notice_writes_to_per_user_transient(): void
    {
        $func = $this->extractFunction($this->handlerContent, 'contai_redirect_with_notice');
        $this->assertNotNull($func, 'contai_redirect_with_notice must exist (#54)');

        $this->assertStringContainsString('get_current_user_id()', $func);
        $this->assertStringContainsString('contai_site_gen_notice_transient_key( $user_id )', $func);
        $this->assertStringContainsString('set_transient(', $func);
        $this->assertStringContainsString('wp_safe_redirect(', $func);
        $this->assertStringContainsString('exit;', $func);
    }

    // ── Processing: Validation Returns ─────────────────────────────

    public function test_processing_function_signature_has_no_redirect_param(): void
    {
        $this->assertStringContainsString(
            'function contai_process_site_generation_submission()',
            $this->handlerContent,
            'Processing function must accept no redirect_url param — errors are returned to the handler (#54)'
        );
    }

    public function test_processing_returns_error_on_empty_category(): void
    {
        $this->assertReturnErrorPattern(
            'empty( $site_category )',
            'Please select a category',
            'Empty category must return error array (#54)'
        );
    }

    public function test_processing_returns_error_on_no_credits(): void
    {
        $this->assertReturnErrorPattern(
            "! \$creditCheck['has_credits']",
            "\$creditCheck['message']",
            'Insufficient credits must return error array (#54)'
        );
    }

    public function test_processing_returns_error_on_active_job(): void
    {
        $this->assertReturnErrorPattern(
            '$activeJob',
            'already an active site generation',
            'Active job must return error array (#54)'
        );
    }

    public function test_processing_returns_error_on_job_creation_failure(): void
    {
        $this->assertReturnErrorPattern(
            '! $created',
            'Failed to start site generation',
            'Job creation failure must return error array (#54)'
        );
    }

    public function test_processing_uses_only_helper_for_redirects(): void
    {
        $processingFunc = $this->extractFunction($this->handlerContent, 'contai_process_site_generation_submission');
        $this->assertNotNull($processingFunc, 'Processing function must exist');

        // Errors return arrays.
        $returnArrayCount = substr_count($processingFunc, 'return array(');
        $this->assertGreaterThanOrEqual(4, $returnArrayCount,
            'Processing function must have at least 4 error return paths (category, credits, active job, job creation)'
        );

        // Success path delegates redirecting to the helper.
        $this->assertStringContainsString(
            'contai_redirect_with_notice(',
            $processingFunc,
            'Success path must redirect via the contai_redirect_with_notice helper (#54)'
        );
        $this->assertStringNotContainsString(
            'wp_safe_redirect',
            $processingFunc,
            'Processing function must not call wp_safe_redirect directly — use the helper (#54)'
        );
    }

    public function test_processing_saves_adsense_publisher_on_success(): void
    {
        $processingFunc = $this->extractFunction($this->handlerContent, 'contai_process_site_generation_submission');
        $this->assertStringContainsString(
            "update_option( 'contai_adsense_publishers'",
            $processingFunc,
            'Success path must save AdSense publisher ID (fixes #12)'
        );
        $this->assertStringContainsString(
            '/^pub-\\d+$/',
            $processingFunc,
            'AdSense publisher ID must be validated with regex before saving'
        );
    }

    // ── Page Renderer ──────────────────────────────────────────────

    public function test_page_renderer_consumes_per_user_transient(): void
    {
        $rendererFunc = $this->extractFunction($this->handlerContent, 'contai_ai_site_generator_page');
        $this->assertNotNull($rendererFunc, 'Page renderer function must exist');

        $this->assertStringContainsString(
            'contai_site_gen_notice_transient_key( $user_id )',
            $rendererFunc,
            'Page renderer must read from the per-user transient key (#54)'
        );
        $this->assertStringContainsString(
            'delete_transient( $transient_key )',
            $rendererFunc,
            'Page renderer must delete the transient after reading to prevent stale notices (#54)'
        );
    }

    public function test_page_renderer_exposes_preserved_form_data_to_form(): void
    {
        $rendererFunc = $this->extractFunction($this->handlerContent, 'contai_ai_site_generator_page');
        $this->assertStringContainsString(
            "GLOBALS['contai_site_gen_preserved_form_data']",
            $rendererFunc,
            'Page renderer must expose preserved form values to the form template (#54)'
        );
    }

    public function test_page_renderer_validates_notice_type(): void
    {
        $rendererFunc = $this->extractFunction($this->handlerContent, 'contai_ai_site_generator_page');

        $this->assertStringContainsString(
            "in_array( \$notice['type'], array( 'success', 'error', 'warning', 'info' ), true )",
            $rendererFunc,
            'Notice type must be validated against allowed values'
        );
    }

    public function test_page_renderer_escapes_notice_output(): void
    {
        $rendererFunc = $this->extractFunction($this->handlerContent, 'contai_ai_site_generator_page');

        $this->assertStringContainsString('esc_attr( $type )', $rendererFunc, 'Notice type must be escaped with esc_attr');
        $this->assertStringContainsString('esc_html( $msg )', $rendererFunc, 'Notice message must be escaped with esc_html');
    }

    // ── Form: POST Data Preservation ───────────────────────────────

    public function test_form_reads_preserved_values_from_transient_bridge(): void
    {
        $this->assertStringContainsString(
            "GLOBALS['contai_site_gen_preserved_form_data']",
            $this->formContent,
            'Form must read preserved values from the transient bridge global (#54)'
        );
    }

    public function test_form_post_closure_does_not_touch_post(): void
    {
        // The new approach pulls from the sanitized transient stash, never from $_POST.
        $this->assertStringNotContainsString(
            'sanitize_text_field( wp_unslash( $_POST[ $key ] ) )',
            $this->formContent,
            'Form must no longer re-sanitize from $_POST — values come pre-sanitized from the transient (#54)'
        );
    }

    public function test_form_preserves_all_text_inputs_on_error(): void
    {
        $fields = [
            'contai_site_topic',
            'contai_legal_owner',
            'contai_legal_email',
            'contai_legal_activity',
            'contai_legal_address',
            'contai_source_topic',
            'contai_adsense_publisher',
            'contai_num_posts',
            'contai_comments_per_post',
        ];

        foreach ($fields as $field) {
            $this->assertStringContainsString(
                "\$post( '{$field}'",
                $this->formContent,
                "Form field {$field} must use \$post() for value preservation on error (#54)"
            );
        }
    }

    public function test_form_preserves_select_values_on_error(): void
    {
        $selects = [
            'contai_site_category',
            'contai_site_language',
            'contai_target_country',
            'contai_image_provider',
        ];

        foreach ($selects as $field) {
            $this->assertStringContainsString(
                "\$post( '{$field}'",
                $this->formContent,
                "Select field {$field} must use \$post() for value retrieval (#54)"
            );
        }
    }

    public function test_form_text_inputs_escape_with_esc_attr(): void
    {
        preg_match_all('/value="<\?php echo esc_attr\( \$post\(/', $this->formContent, $matches);

        $this->assertGreaterThanOrEqual(7, count($matches[0]),
            'At least 7 text inputs must use esc_attr( $post(...) ) pattern for XSS prevention'
        );
    }

    // ── Form: Provider Name Masking ────────────────────────────────

    public function test_form_does_not_expose_provider_names_as_visible_text(): void
    {
        $this->assertStringNotContainsString(
            '>Pexels<',
            $this->formContent,
            'Form must not expose Pexels provider name in visible text (CRITICAL RULE)'
        );
        $this->assertStringNotContainsString(
            '>Pixabay<',
            $this->formContent,
            'Form must not expose Pixabay provider name in visible text (CRITICAL RULE)'
        );
    }

    public function test_form_uses_generic_labels_for_image_providers(): void
    {
        $this->assertStringContainsString(
            'Stock Photos (Free)',
            $this->formContent,
            'Pexels option must use generic label "Stock Photos (Free)"'
        );
        $this->assertStringContainsString(
            'Stock Images (Free)',
            $this->formContent,
            'Pixabay option must use generic label "Stock Images (Free)"'
        );
    }

    public function test_form_image_labels_are_translatable(): void
    {
        $this->assertStringContainsString(
            "esc_html_e( 'Stock Photos (Free)'",
            $this->formContent,
            'Stock Photos label must be translatable via esc_html_e()'
        );
        $this->assertStringContainsString(
            "esc_html_e( 'Stock Images (Free)'",
            $this->formContent,
            'Stock Images label must be translatable via esc_html_e()'
        );
    }

    // ── Form: AdSense Publisher ID Optional (#101) ─────────────────

    public function test_adsense_publisher_input_is_not_required(): void
    {
        $line = $this->findLineContaining($this->formContent, 'id="contai_adsense_publisher"');

        $this->assertNotNull($line, 'AdSense publisher input line must exist');
        $this->assertStringNotContainsString(
            ' required',
            $line,
            'AdSense Publisher ID input must NOT be required — users without AdSense must be able to submit (#101)'
        );
    }

    public function test_adsense_publisher_label_marks_field_as_optional(): void
    {
        $labelBlock = $this->extractBlock(
            $this->formContent,
            'for="contai_adsense_publisher"',
            '</label>'
        );

        $this->assertNotNull($labelBlock, 'AdSense publisher label block must exist');
        $this->assertStringNotContainsString(
            'contai-required',
            $labelBlock,
            'AdSense Publisher ID label must NOT show required asterisk (#101)'
        );
        $this->assertStringContainsString(
            'contai-optional',
            $labelBlock,
            'AdSense Publisher ID label must indicate the field is optional (#101)'
        );
    }

    public function test_adsense_publisher_keeps_format_pattern_when_provided(): void
    {
        $line = $this->findLineContaining($this->formContent, 'id="contai_adsense_publisher"');

        $this->assertNotNull($line, 'AdSense publisher input line must exist');
        $this->assertMatchesRegularExpression(
            '/pattern="pub-\\\\d\{[^}]+\}"/',
            $line,
            'AdSense Publisher ID must enforce "pub-<digits>" format when provided, even though empty is allowed (#101)'
        );
    }

    public function test_adsense_publisher_backend_still_validates_when_non_empty(): void
    {
        $processingFunc = $this->extractFunction($this->handlerContent, 'contai_process_site_generation_submission');
        $this->assertStringContainsString(
            '! empty( $adsense_publisher )',
            $processingFunc,
            'Backend must guard save with ! empty() so the field being optional does not blank saved values (#101)'
        );
        $this->assertStringContainsString(
            '/^pub-\\d+$/',
            $processingFunc,
            'Backend must still validate format with regex when a value is provided (#101)'
        );
    }

    // ── Form: Security ─────────────────────────────────────────────

    public function test_form_has_nonce_field(): void
    {
        $this->assertStringContainsString(
            "wp_nonce_field( 'contai_site_generator_nonce', 'contai_site_generator_nonce' )",
            $this->formContent,
            'Form must include nonce field for CSRF protection'
        );
    }

    public function test_form_has_explicit_action_attribute(): void
    {
        $this->assertStringContainsString(
            'action="<?php echo esc_url( admin_url(',
            $this->formContent,
            'Form must have an explicit action attribute with admin_url() to prevent URL resolution issues (#54)'
        );
    }

    public function test_form_has_hidden_submission_marker(): void
    {
        $this->assertStringContainsString(
            'name="contai_start_site_generation" value="1"',
            $this->formContent,
            'Form must include hidden field for handler detection'
        );
    }

    // ── Structural Integrity ───────────────────────────────────────

    public function test_handler_registered_on_admin_init(): void
    {
        $this->assertStringContainsString(
            "add_action( 'admin_init', 'contai_handle_ai_site_generator_submission' )",
            $this->handlerContent,
            'Handler must be registered on admin_init hook'
        );
    }

    public function test_no_url_param_based_error_messages_in_handler(): void
    {
        $this->assertStringNotContainsString(
            "'error' => 1",
            $this->handlerContent,
            'Handler should not use URL params for error messages — use transients (#54)'
        );
    }

    // ── Helper Methods ─────────────────────────────────────────────

    private function findLineContaining(string $content, string $needle): ?string
    {
        foreach (preg_split('/\R/', $content) as $line) {
            if (strpos($line, $needle) !== false) {
                return $line;
            }
        }
        return null;
    }

    private function extractBlock(string $content, string $startMarker, string $endMarker): ?string
    {
        $startPos = strpos($content, $startMarker);
        if ($startPos === false) {
            return null;
        }
        $endPos = strpos($content, $endMarker, $startPos);
        if ($endPos === false) {
            return null;
        }
        return substr($content, $startPos, $endPos - $startPos + strlen($endMarker));
    }

    private function extractFunction(string $content, string $funcName): ?string
    {
        $needle = 'function ' . $funcName;
        $startPos = strpos($content, $needle);
        if ($startPos === false) {
            return null;
        }
        // Walk forward to the first '{' that opens the function body.
        $bodyStart = strpos($content, '{', $startPos);
        if ($bodyStart === false) {
            return null;
        }
        $braceCount = 0;
        $len        = strlen($content);

        for ($i = $bodyStart; $i < $len; $i++) {
            if ($content[$i] === '{') {
                $braceCount++;
            } elseif ($content[$i] === '}') {
                $braceCount--;
                if ($braceCount === 0) {
                    return substr($content, $startPos, $i - $startPos + 1);
                }
            }
        }
        return null;
    }

    private function assertReturnErrorPattern(string $condition, string $_messageHint, string $description): void
    {
        $condPos = strpos($this->handlerContent, $condition);
        $this->assertNotFalse($condPos, "Condition '{$condition}' must exist in handler");

        $returnPos = strpos($this->handlerContent, 'return array(', $condPos);
        $this->assertNotFalse($returnPos, "Return array must follow condition: {$description}");

        $block = substr($this->handlerContent, $returnPos, 200);
        $this->assertStringContainsString("'type'    => 'error'", $block, $description);
    }
}
