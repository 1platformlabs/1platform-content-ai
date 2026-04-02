<?php

namespace ContAI\Tests\Unit\Admin\SiteGenerator;

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for Site Wizard re-execution visibility.
 *
 * Validates fixes for GitHub issue #55: Site Wizard re-execution
 * didn't apply changes because:
 * 1. Failed jobs were invisible — findActiveSiteGenerationJob() excluded FAILED status
 * 2. No ensureWebsiteExists() in the generation flow
 * 3. Profile transient cache prevented fresh widget generation
 */
class SiteGeneratorReExecutionTest extends TestCase
{
    private string $handlerFile;
    private string $jobFile;
    private string $repoFile;
    private string $helperFile;

    public function setUp(): void
    {
        parent::setUp();
        $this->handlerFile = dirname(__DIR__, 4) . '/includes/admin/admin-ai-site-generator.php';
        $this->jobFile = dirname(__DIR__, 4) . '/includes/services/jobs/SiteGenerationJob.php';
        $this->repoFile = dirname(__DIR__, 4) . '/includes/database/repositories/JobRepository.php';
        $this->helperFile = dirname(__DIR__, 4) . '/includes/helpers/site-generation.php';
    }

    public function test_repository_has_findLastSiteGenerationJob_method(): void
    {
        $content = file_get_contents($this->repoFile);

        $this->assertStringContainsString(
            'public function findLastSiteGenerationJob()',
            $content,
            'JobRepository must have findLastSiteGenerationJob() to retrieve last job regardless of status (#55)'
        );
    }

    public function test_findLastSiteGenerationJob_queries_all_statuses(): void
    {
        $content = file_get_contents($this->repoFile);

        // Extract the findLastSiteGenerationJob method body
        $methodStart = strpos($content, 'public function findLastSiteGenerationJob()');
        $this->assertNotFalse($methodStart, 'Method must exist');

        $methodBody = substr($content, $methodStart, 800);

        // Must NOT filter by status (unlike findActiveSiteGenerationJob)
        $this->assertStringNotContainsString(
            'AND status IN',
            $methodBody,
            'findLastSiteGenerationJob must not filter by status — it returns the most recent job of any status (#55)'
        );

        $this->assertStringContainsString(
            'ORDER BY created_at DESC',
            $methodBody,
            'Must order by created_at DESC to get the most recent job'
        );
    }

    public function test_page_renderer_calls_last_job_notice(): void
    {
        $content = file_get_contents($this->handlerFile);

        $this->assertStringContainsString(
            'contai_render_last_job_notice',
            $content,
            'Page renderer must call contai_render_last_job_notice to show failed job errors (#55)'
        );
    }

    public function test_last_job_notice_function_exists(): void
    {
        $content = file_get_contents($this->handlerFile);

        $this->assertStringContainsString(
            'function contai_render_last_job_notice',
            $content,
            'contai_render_last_job_notice() function must be defined (#55)'
        );
    }

    public function test_last_job_notice_shows_error_for_failed_jobs(): void
    {
        $content = file_get_contents($this->handlerFile);

        // Extract the function body
        $funcStart = strpos($content, 'function contai_render_last_job_notice');
        $this->assertNotFalse($funcStart);

        $funcBody = substr($content, $funcStart, 1500);

        $this->assertStringContainsString(
            "=== 'failed'",
            $funcBody,
            'Must check for failed status to show error notice (#55)'
        );

        $this->assertStringContainsString(
            '$lastJob->getErrorMessage()',
            $funcBody,
            'Must display the error message from the failed job (#55)'
        );
    }

    public function test_activate_license_ensures_website_exists(): void
    {
        $content = file_get_contents($this->jobFile);

        $this->assertStringContainsString(
            'ensureWebsiteExists',
            $content,
            'activateLicense step must call ensureWebsiteExists() to guarantee API operations work (#55)'
        );
    }

    public function test_sidebar_widgets_clear_profile_cache_before_generation(): void
    {
        $content = file_get_contents($this->helperFile);

        // Find contai_add_sidebar_widgets function
        $funcStart = strpos($content, 'function contai_add_sidebar_widgets()');
        $this->assertNotFalse($funcStart);

        // Check that delete_transient is called BEFORE contai_fetch_generated_profile_from_api
        $funcBody = substr($content, $funcStart, 2000);
        $deletePos = strpos($funcBody, 'delete_transient');
        $fetchPos = strpos($funcBody, 'contai_fetch_generated_profile_from_api');

        $this->assertNotFalse($deletePos, 'Must call delete_transient to clear profile cache (#55)');
        $this->assertNotFalse($fetchPos, 'Must call contai_fetch_generated_profile_from_api');
        $this->assertLessThan(
            $fetchPos,
            $deletePos,
            'Profile cache must be cleared BEFORE fetching new profile (#55)'
        );
    }

    public function test_website_provider_explicitly_required_in_site_generation_job(): void
    {
        $content = file_get_contents($this->jobFile);

        $this->assertStringContainsString(
            "require_once __DIR__ . '/../../providers/WebsiteProvider.php'",
            $content,
            'SiteGenerationJob must explicitly require WebsiteProvider for ensureWebsiteExists() (#55)'
        );
    }
}
