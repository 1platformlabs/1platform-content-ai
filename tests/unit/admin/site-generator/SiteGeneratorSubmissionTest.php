<?php

namespace ContAI\Tests\Unit\Admin\SiteGenerator;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the Site Wizard form submission handler and form rendering.
 *
 * Covers GitHub issue #54: "Launch Site Generation" refreshed the page
 * silently without executing any action or showing error/success feedback.
 *
 * The fix replaced redirect-on-error with inline error display via
 * $GLOBALS['contai_site_gen_inline_notice'], preserving form data on
 * validation failures. Only the success path redirects.
 *
 * Tests are organized by branch coverage:
 * - Handler branches (nonce fail, capability, error return, exception)
 * - Processing branches (category, credits, active job, job creation, success)
 * - Page renderer (inline notice priority, transient fallback)
 * - Form (POST data preservation, provider masking, escaping)
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
        $this->handlerFile = dirname(__DIR__, 4) . '/includes/admin/admin-ai-site-generator.php';
        $this->formFile = dirname(__DIR__, 4) . '/includes/admin/ai-site-generator/site-generator-form.php';
        $this->handlerContent = file_get_contents($this->handlerFile);
        $this->formContent = file_get_contents($this->formFile);
    }

    // ── Handler: Entry Guard ───────────────────────────────────────

    public function test_handler_returns_early_when_no_post_data(): void
    {
        // Branch: line 37 — if ( ! isset( $_POST['contai_start_site_generation'] ) )
        $this->assertStringContainsString(
            "if ( ! isset( \$_POST['contai_start_site_generation'] ) )",
            $this->handlerContent,
            'Handler must check for POST submission marker before processing'
        );

        // Must return immediately — no GLOBALS, no redirect
        $earlyReturn = $this->extractBlock($this->handlerContent, "isset( \$_POST['contai_start_site_generation']", 'return;');
        $this->assertNotNull($earlyReturn, 'Early return must follow POST check');
        $this->assertStringNotContainsString('GLOBALS', $earlyReturn, 'Early return must not set any GLOBALS');
        $this->assertStringNotContainsString('wp_safe_redirect', $earlyReturn, 'Early return must not redirect');
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

    public function test_nonce_failure_sets_inline_notice_not_redirect(): void
    {
        // Branch: line 46 — if ( ! wp_verify_nonce(...) )
        $nonceBlock = $this->extractBlock($this->handlerContent, '! wp_verify_nonce(', "return;\n\t}");
        $this->assertNotNull($nonceBlock, 'Nonce failure block must exist');

        $this->assertStringContainsString(
            "GLOBALS['contai_site_gen_inline_notice']",
            $nonceBlock,
            'Nonce failure must set inline notice (#54)'
        );

        $this->assertStringNotContainsString(
            'wp_safe_redirect',
            $nonceBlock,
            'Nonce failure must NOT redirect — error shown inline (#54)'
        );

        $this->assertStringNotContainsString(
            'set_transient',
            $nonceBlock,
            'Nonce failure must NOT use transient — inline notice only (#54)'
        );
    }

    public function test_nonce_failure_notice_has_correct_type(): void
    {
        $nonceBlock = $this->extractBlock($this->handlerContent, '! wp_verify_nonce(', "return;\n\t}");
        $this->assertNotNull($nonceBlock, 'Nonce failure block must exist');
        $this->assertStringContainsString("'type'    => 'error'", $nonceBlock);
        $this->assertStringContainsString('session has expired', $nonceBlock);
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

    public function test_handler_stores_processing_error_in_inline_notice(): void
    {
        // Branch: line 60 — if ( $error ) { $GLOBALS[...] = $error; return; }
        $this->assertStringContainsString(
            '$error = contai_process_site_generation_submission()',
            $this->handlerContent,
            'Handler must capture return value from processing function'
        );

        $this->assertStringContainsString(
            "if ( \$error ) {\n\t\t\t\$GLOBALS['contai_site_gen_inline_notice'] = \$error;",
            $this->handlerContent,
            'Handler must set inline notice when processing returns error (#54)'
        );
    }

    public function test_exception_handler_logs_and_sets_inline_notice(): void
    {
        // Branch: line 64-71 — catch block
        $catchBlock = $this->extractBlock($this->handlerContent, 'catch ( \\Throwable $e )', 'return;');
        $this->assertNotNull($catchBlock, 'Catch block must exist');

        $this->assertStringContainsString(
            'contai_log(',
            $catchBlock,
            'Exception handler must log the error for debugging (#54)'
        );

        $this->assertStringContainsString(
            "GLOBALS['contai_site_gen_inline_notice']",
            $catchBlock,
            'Exception handler must set inline notice (#54)'
        );

        $this->assertStringNotContainsString(
            'wp_safe_redirect',
            $catchBlock,
            'Exception handler must NOT redirect (#54)'
        );
    }

    // ── Handler: No Redirect on Errors ─────────────────────────────

    public function test_handler_never_redirects_in_error_paths(): void
    {
        // The handler function should NOT contain wp_safe_redirect at all
        // Only the processing function redirects on success
        $handlerFunc = $this->extractFunction($this->handlerContent, 'contai_handle_ai_site_generator_submission');
        $this->assertNotNull($handlerFunc, 'Handler function must exist');

        $this->assertStringNotContainsString(
            'wp_safe_redirect',
            $handlerFunc,
            'Handler must never redirect — only processing function redirects on success (#54)'
        );
    }

    // ── Processing: Validation Returns ─────────────────────────────

    public function test_processing_function_signature_has_no_redirect_param(): void
    {
        $this->assertStringContainsString(
            'function contai_process_site_generation_submission()',
            $this->handlerContent,
            'Processing function must accept no redirect_url param — errors are returned inline (#54)'
        );
    }

    public function test_processing_returns_error_on_empty_category(): void
    {
        // Branch: line 86 — if ( empty( $site_category ) )
        $this->assertReturnErrorPattern(
            'empty( $site_category )',
            'Please select a category',
            'Empty category must return error array (#54)'
        );
    }

    public function test_processing_returns_error_on_no_credits(): void
    {
        // Branch: line 98 — if ( ! $creditCheck['has_credits'] )
        $this->assertReturnErrorPattern(
            "! \$creditCheck['has_credits']",
            "\$creditCheck['message']",
            'Insufficient credits must return error array (#54)'
        );
    }

    public function test_processing_returns_error_on_active_job(): void
    {
        // Branch: line 108 — if ( $activeJob )
        $this->assertReturnErrorPattern(
            '$activeJob',
            'already an active site generation',
            'Active job must return error array (#54)'
        );
    }

    public function test_processing_returns_error_on_job_creation_failure(): void
    {
        // Branch: line 163 — if ( ! $created )
        $this->assertReturnErrorPattern(
            '! $created',
            'Failed to start site generation',
            'Job creation failure must return error array (#54)'
        );
    }

    public function test_processing_error_returns_use_return_not_redirect(): void
    {
        $processingFunc = $this->extractFunction($this->handlerContent, 'contai_process_site_generation_submission');
        $this->assertNotNull($processingFunc, 'Processing function must exist');

        // Count error paths: should use 'return array(' not 'wp_safe_redirect'
        $returnArrayCount = substr_count($processingFunc, 'return array(');
        $this->assertGreaterThanOrEqual(4, $returnArrayCount,
            'Processing function must have at least 4 error return paths (category, credits, active job, job creation)'
        );

        // Only ONE redirect (success path)
        $redirectCount = substr_count($processingFunc, 'wp_safe_redirect');
        $this->assertEquals(1, $redirectCount,
            'Processing function must have exactly 1 redirect (success path only)'
        );
    }

    // ── Processing: Success Path ───────────────────────────────────

    public function test_processing_success_uses_transient_and_redirect(): void
    {
        $this->assertStringContainsString(
            "set_transient( 'contai_site_gen_notice'",
            $this->handlerContent,
            'Success path must use transient for notice delivery (#54)'
        );

        // Verify success transient contains success type
        $successBlock = $this->extractBlock($this->handlerContent, "// Success — redirect", 'exit;');
        $this->assertNotNull($successBlock, 'Success block must exist');

        $this->assertStringContainsString("'type'    => 'success'", $successBlock);
        $this->assertStringContainsString('wp_safe_redirect', $successBlock);
        $this->assertStringContainsString('exit;', $successBlock);
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

    // ── Page Renderer: Notice Priority ─────────────────────────────

    public function test_page_renderer_checks_inline_notice_first(): void
    {
        $rendererFunc = $this->extractFunction($this->handlerContent, 'contai_ai_site_generator_page');
        $this->assertNotNull($rendererFunc, 'Page renderer function must exist');

        $inlinePos = strpos($rendererFunc, "GLOBALS['contai_site_gen_inline_notice']");
        $transientPos = strpos($rendererFunc, "get_transient( 'contai_site_gen_notice' )");

        $this->assertNotFalse($inlinePos, 'Page renderer must check inline notice');
        $this->assertNotFalse($transientPos, 'Page renderer must check transient as fallback');
        $this->assertLessThan(
            $transientPos,
            $inlinePos,
            'Inline notice must be checked before transient fallback in page renderer (#54)'
        );
    }

    public function test_page_renderer_deletes_transient_after_reading(): void
    {
        $rendererFunc = $this->extractFunction($this->handlerContent, 'contai_ai_site_generator_page');

        $this->assertStringContainsString(
            "delete_transient( 'contai_site_gen_notice' )",
            $rendererFunc,
            'Page render must delete transient after display to prevent stale notices (#54)'
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

    public function test_form_detects_post_data_via_inline_notice(): void
    {
        $this->assertStringContainsString(
            "! empty( \$GLOBALS['contai_site_gen_inline_notice'] ) && isset( \$_POST['contai_start_site_generation'] )",
            $this->formContent,
            'Form must detect POST data availability by checking inline notice AND POST marker (#54)'
        );
    }

    public function test_form_post_closure_sanitizes_values(): void
    {
        $this->assertStringContainsString(
            'sanitize_text_field( wp_unslash( $_POST[ $key ] ) )',
            $this->formContent,
            'POST data closure must sanitize all values with sanitize_text_field + wp_unslash'
        );
    }

    public function test_form_post_closure_returns_default_when_no_post(): void
    {
        // When $has_post_data is false, closure must return default
        $this->assertStringContainsString(
            "if ( ! \$has_post_data || ! isset( \$_POST[ \$key ] ) )",
            $this->formContent,
            'POST closure must check both data availability and key existence'
        );

        $this->assertStringContainsString(
            'return $default;',
            $this->formContent,
            'POST closure must return default value when no POST data'
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
            'contai_site_category'   => 'selected_category',
            'contai_site_language'   => 'post_language',
            'contai_target_country'  => 'post_country',
            'contai_image_provider'  => 'post_image',
        ];

        foreach ($selects as $field => $_var) {
            $this->assertStringContainsString(
                "\$post( '{$field}'",
                $this->formContent,
                "Select field {$field} must use \$post() for POST data retrieval (#54)"
            );
        }
    }

    public function test_form_text_inputs_escape_with_esc_attr(): void
    {
        // Every $post() call in a value attribute must be wrapped with esc_attr()
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

    /**
     * Find the first line containing a substring.
     */
    private function findLineContaining(string $content, string $needle): ?string
    {
        foreach (preg_split('/\R/', $content) as $line) {
            if (strpos($line, $needle) !== false) {
                return $line;
            }
        }
        return null;
    }

    /**
     * Extract a code block between two markers.
     */
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

    /**
     * Extract a function body from source code.
     */
    private function extractFunction(string $content, string $funcName): ?string
    {
        $pattern = '/function\s+' . preg_quote($funcName, '/') . '\s*\([^)]*\)\s*\{/';
        if (!preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $startPos = $matches[0][1];
        $braceCount = 0;
        $len = strlen($content);
        $started = false;

        for ($i = $startPos; $i < $len; $i++) {
            if ($content[$i] === '{') {
                $braceCount++;
                $started = true;
            } elseif ($content[$i] === '}') {
                $braceCount--;
                if ($started && $braceCount === 0) {
                    return substr($content, $startPos, $i - $startPos + 1);
                }
            }
        }
        return null;
    }

    /**
     * Assert that a condition triggers a return array('type' => 'error') pattern.
     */
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
