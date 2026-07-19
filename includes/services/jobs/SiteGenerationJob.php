<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/JobInterface.php';
require_once __DIR__ . '/../../database/repositories/JobRepository.php';
require_once __DIR__ . '/../user-profile/UserProfileService.php';
require_once __DIR__ . '/../setup/SiteConfigService.php';
require_once __DIR__ . '/../setup/LegalInfoService.php';
require_once __DIR__ . '/../setup/WebsiteGenerationService.php';
require_once __DIR__ . '/../../providers/WebsiteProvider.php';
require_once __DIR__ . '/../keyword/KeywordExtractorService.php';
require_once __DIR__ . '/../setup/PostGenerationSetupService.php';
require_once __DIR__ . '/../setup/CommentsGenerationService.php';
require_once __DIR__ . '/../setup/SearchConsoleSetupService.php';
require_once __DIR__ . '/../setup/AdsenseSetupService.php';
require_once __DIR__ . '/../menu/MainMenuManager.php';
require_once __DIR__ . '/../../helpers/category-menu.php';
require_once __DIR__ . '/../../helpers/site-warnings.php';

class ContaiSiteGenerationJob implements ContaiJobInterface
{
    const TYPE = 'site_generation';

    /**
     * Option holding a bounded FIFO of optional-step failures (#48).
     *
     * Read it with: wp option get contai_site_generation_warnings
     */
    const OPTION_STEP_WARNINGS = 'contai_site_generation_warnings';

    /** Keep the warning buffer bounded, like ContaiClientLogReporter does. */
    const MAX_STEP_WARNINGS = 20;

    private ContaiJobRepository $jobRepository;
    private array $steps = [
        'validateCredits',
        'activateLicense',
        'saveSiteConfig',
        'saveLegalInfo',
        'generateWebsite',
        'extractKeywords',
        'enqueuePosts',
        'waitForPosts',
        'generateComments',
        'setupSearchConsole',
        'setupAdsManager',
        'setupNavigation'
    ];

    public function __construct()
    {
        $this->jobRepository = new ContaiJobRepository();
    }

    public function getStepCount(): int
    {
        return count($this->steps);
    }

    public function handle(array $payload)
    {
        //set_time_limit(300);
        //ini_set('max_execution_time', '300');

        $jobId = $payload['job_id'] ?? null;
        $currentStep = $payload['progress']['current_step'] ?? 0;
        $completedSteps = $payload['progress']['completed_steps'] ?? [];

        $stepName = $this->steps[$currentStep] ?? null;

        if (!$stepName) {
            return [
                'success' => true,
                'message' => 'Site generation completed successfully'
            ];
        }

        try {
            $result = $this->executeStep($stepName, $payload);

            if (isset($result['_wait_and_retry'])) {
                $this->updateJobPayloadWithoutAdvancing($jobId, $result, $currentStep, $completedSteps);
                $this->markJobAsPending($jobId);
                return [
                    'success' => false,
                    'retry' => true,
                    'message' => $result['_wait_message'] ?? 'Step not ready, will retry'
                ];
            }

            $payload = $result;
            $completedSteps[] = $stepName;
            $this->updateJobPayload($jobId, $payload, $currentStep, $completedSteps);

            if ($currentStep + 1 < count($this->steps)) {
                $this->markJobAsPending($jobId);
                return [
                    'success' => false,
                    'continue' => true,
                    'message' => "Step '{$stepName}' completed, continuing to next step"
                ];
            }

            return [
                'success' => true,
                'message' => 'Site generation completed successfully'
            ];
        } catch (Exception $e) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new Exception("Step '{$stepName}' failed: " . $e->getMessage());
        }
    }

    private function executeStep(string $stepName, array $payload): array
    {
        $config = $payload['config'] ?? [];

        switch ($stepName) {
            case 'validateCredits':
                $this->validateCreditsStep();
                break;

            case 'activateLicense':
                $this->activateLicense();
                break;

            case 'saveSiteConfig':
                $this->saveSiteConfig($config['site_config']);
                break;

            case 'saveLegalInfo':
                $this->saveLegalInfo($config['legal_info']);
                break;

            case 'generateWebsite':
                $this->generateWebsite();
                break;

            case 'extractKeywords':
                $this->cleanPreviousDataIfReExecution();
                $this->extractKeywords($config['keyword_extraction']);
                break;

            case 'enqueuePosts':
                $batchId = $this->enqueuePosts($config['post_generation']);
                $payload['batch_id'] = $batchId;
                break;

            case 'waitForPosts':
                $payload = $this->waitForPosts($payload['batch_id'] ?? '', $payload);
                break;

            case 'generateComments':
                $this->runOptionalStep('generateComments', function () use ($config) {
                    $this->generateComments($config['comments'] ?? []);
                });
                break;

            case 'setupSearchConsole':
                $this->runOptionalStep('setupSearchConsole', function () {
                    $this->setupSearchConsole();
                });
                break;

            case 'setupAdsManager':
                $this->runOptionalStep('setupAdsManager', function () use ($config) {
                    $this->setupAdsManager($config['adsense']['publisher_id'] ?? '');
                });
                break;

            case 'setupNavigation':
                $this->runOptionalStep('setupNavigation', function () {
                    $this->setupNavigation();
                });
                update_option('contai_site_generation_completed', true);
                break;
        }
        return $payload;
    }

    /**
     * Run an optional wizard step without aborting site generation.
     *
     * Optional used to mean catch(Exception) + contai_log(), and contai_log()
     * only writes when WP_DEBUG is true (includes/helpers/crypto.php:72-76).
     * Production installs run with WP_DEBUG off, so a failure of
     * generateComments, setupSearchConsole, setupAdsManager or setupNavigation
     * left no trace of any kind: handle() still appended the step to
     * completed_steps, still returned "Site generation completed successfully"
     * and still set contai_site_generation_completed to true. A site could
     * therefore finish the wizard reporting success while having no primary
     * menu, no categories in the menu and no comments - which is why #48 kept
     * being reopened with no diagnostic to work from.
     *
     * Two things change here. The failure is caught as Throwable: a PHP Error
     * (a TypeError from a theme/API returning an unexpected shape, say) is not
     * an Exception, so it escaped the old catch and was rethrown by handle(),
     * killing the whole run - the exact opposite of "optional". And the
     * failure is recorded durably instead of only in a debug-gated log.
     *
     * @param string   $stepName Step whose failure is being contained.
     * @param callable $step     The step body.
     */
    private function runOptionalStep(string $stepName, callable $step): void
    {
        try {
            $step();
        } catch (\Throwable $e) {
            $this->recordStepWarning($stepName, $e->getMessage());
        }
    }

    /**
     * Record an optional-step failure where it can actually be found.
     *
     * error_log() is called unconditionally on purpose - the same choice
     * already made for the footer-location diagnostic in
     * includes/helpers/site-generation.php - because contai_log() is a no-op
     * without WP_DEBUG. The option gives a durable record that survives the
     * request and can be read back off a generated site without shell access
     * to the PHP error log.
     *
     * @param string $stepName Step that failed.
     * @param string $message  Failure message.
     */
    private function recordStepWarning(string $stepName, string $message): void
    {
        // Delegates to the shared recorder so the nav-menu resolvers in
        // includes/helpers/ write to the SAME durable store, rather than each
        // concern growing its own FIFO (#48).
        contai_record_site_warning(
            $stepName,
            $message,
            "optional site-generation step '{$stepName}' failed"
        );
    }

    private function validateCreditsStep(): void
    {
        require_once __DIR__ . '/../billing/CreditGuard.php';

        $creditGuard = new ContaiCreditGuard();
        $creditCheck = $creditGuard->validateCredits();

        if (!$creditCheck['has_credits']) {
            throw new Exception(
                sprintf(
                    'Insufficient balance (%s %s). Please add credits before generating content.',
                    number_format($creditCheck['balance'], 2),
                    $creditCheck['currency']
                )
            );
        }
    }

    private function activateLicense(): void
    {
        $service = new ContaiUserProfileService();

        if (!$service->hasApiKey()) {
            throw new Exception('License activation failed: No API key configured');
        }

        $result = $service->refreshUserProfile();

        if (!$result['success']) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new Exception('License activation failed: ' . $result['message']);
        }

        // Ensure website record exists in API — required for theme tracking,
        // tagline generation, and API sync in subsequent steps (#55)
        $websiteProvider = new ContaiWebsiteProvider();
        $websiteResult = $websiteProvider->ensureWebsiteExists();

        if (!$websiteResult['success']) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new Exception('Website registration failed: ' . ($websiteResult['message'] ?? 'Unknown error'));
        }
    }

    private function saveSiteConfig(array $siteConfig): void
    {
        $service = new ContaiSiteConfigService();

        $errors = $service->validateSiteConfiguration($siteConfig);
        if (!empty($errors)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new Exception(implode(', ', $errors));
        }

        $service->saveSiteConfiguration($siteConfig);
    }

    private function saveLegalInfo(array $legalInfo): void
    {
        $service = new ContaiLegalInfoService();

        $errors = $service->validateLegalInfo($legalInfo);
        if (!empty($errors)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new Exception(implode(', ', $errors));
        }

        $service->saveLegalInfo($legalInfo);
    }

    private function generateWebsite(): void
    {
        $service = new ContaiWebsiteGenerationService();
        $result = $service->generateCompleteWebsite();

        if (!$result['success']) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new Exception('Website generation failed: ' . implode(', ', $result['errors']));
        }
    }

    private function isReExecution(): bool
    {
        return (bool) get_option('contai_site_generation_completed', false);
    }

    private function cleanPreviousDataIfReExecution(): void
    {
        if (!$this->isReExecution()) {
            return;
        }

        error_log('[ContAI] Re-execution detected — cleaning previous data');

        // Clean categories where ALL posts are wizard-generated
        $categories = get_categories([
            'hide_empty' => false,
            'exclude'    => [get_option('default_category')],
        ]);

        foreach ($categories as $category) {
            $total_posts = $category->count;
            if ($total_posts === 0) {
                wp_delete_term($category->term_id, 'category');
                continue;
            }

            $contai_posts = get_posts([
                'category'    => $category->term_id,
                'meta_key'    => '_1platform_ai_generated',
                'meta_value'  => '1',
                'numberposts' => -1,
                'fields'      => 'ids',
            ]);

            if (count($contai_posts) === $total_posts) {
                wp_delete_term($category->term_id, 'category');
            }
        }

        // Clean orphaned batch options
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'contai\_batch\_%'"
        );

        error_log('[ContAI] Re-execution cleanup completed');
    }

    private function extractKeywords(array $extractionConfig): void
    {
        $service = ContaiKeywordExtractorService::create();

        $topic = $extractionConfig['source_topic'] ?? '';
        $country = $extractionConfig['target_country'] ?? 'us';
        $lang = $extractionConfig['target_language'] ?? 'en';

        $result = $service->extractByTopicAndSave($topic, $country, $lang);

        if (!$result->isSuccess()) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new Exception('Keyword extraction failed: ' . $result->getErrorMessage());
        }
    }

    private function enqueuePosts(array $postConfig): string
    {
        $service = new ContaiPostGenerationSetupService();

        $count = $postConfig['num_posts'] ?? 100;
        $config = [
            'lang' => $postConfig['target_language'] ?? 'en',
            'country' => $postConfig['target_country'] ?? 'us',
            'image_provider' => $postConfig['image_provider'] ?? 'pexels',
        ];

        $result = $service->enqueuePostGeneration($count, $config);

        return $result['batch_id'];
    }

    private function waitForPosts(string $batchId, array $payload): array
    {
        if (empty($batchId)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new Exception('Batch ID is missing');
        }

        $service = new ContaiPostGenerationSetupService();
        $status = $service->getBatchStatus($batchId);

        if ($status['is_complete']) {
            // Fail loudly when the wizard asked for more posts than the queue
            // could enqueue (shortfall). Previously the job marked itself
            // "done" with a partial load (e.g. 12 of 100), matching #109/#110.
            if (!empty($status['is_short'])) {
                contai_log(sprintf(
                    '[site-gen] batch %s short: requested=%d enqueued=%d done=%d failed=%d',
                    $batchId,
                    $status['requested'] ?? 0,
                    $status['total'],
                    $status['completed'],
                    $status['failed']
                ));
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new Exception(sprintf(
                    'Only %d of %d requested posts were generated. The keyword extraction step yielded fewer keywords than requested — broaden the source topic or lower the post count, then re-run the wizard.',
                    $status['completed'],
                    $status['requested'] ?? $status['total']
                ));
            }

            if ($status['failed'] > 0) {
                contai_log("Site generation batch {$batchId} completed with {$status['failed']} failed posts out of {$status['total']} total.");
            }
            unset($payload['wait_start_time']);
            unset($payload['_wait_and_retry']);
            unset($payload['_wait_message']);
            return $payload;
        }

        $maxWaitSeconds = 7200;
        $waitStartTime = $payload['wait_start_time'] ?? time();
        $elapsedTime = time() - $waitStartTime;

        if ($elapsedTime > $maxWaitSeconds) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new Exception('Timeout waiting for posts to complete after ' . round($elapsedTime / 60) . ' minutes. Progress: ' . $status['finished'] . '/' . $status['total'] . ' finished (' . $status['failed'] . ' failed).');
        }

        $failedInfo = $status['failed'] > 0 ? " ({$status['failed']} failed)" : '';
        // Report progress against the requested target so the UI shows
        // "12/100" instead of "12/12" when the queue ran short.
        $progressDenominator = max($status['total'], $status['requested'] ?? $status['total']);
        $payload['wait_start_time'] = $waitStartTime;
        $payload['_wait_and_retry'] = true;
        $payload['_wait_message'] = "Waiting for posts: {$status['finished']}/{$progressDenominator} finished{$failedInfo}. Elapsed: " . round($elapsedTime / 60) . " min.";

        return $payload;
    }

    private function generateComments(array $commentsConfig): void
    {
        $service = new ContaiCommentsGenerationService();

        $numPosts = $commentsConfig['num_posts'] ?? 20;
        $commentsPerPost = $commentsConfig['comments_per_post'] ?? 1;

        $service->generateCommentsForRecentPosts($numPosts, $commentsPerPost);
    }

    private function setupSearchConsole(): void
    {
        $service = new ContaiSearchConsoleSetupService();
        $result = $service->activateSearchConsole();

        if (!$result['success']) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new Exception('Search Console setup failed: ' . implode(', ', $result['errors']));
        }
    }

    private function setupAdsManager(string $publisherId): void
    {
        $service = new ContaiAdsenseSetupService();

        $errors = $service->validatePublisherId($publisherId);
        if (!empty($errors)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new Exception(implode(', ', $errors));
        }

        $service->setupAdsense($publisherId);
    }

    private function setupNavigation(): void
    {
        // Do NOT exclude default_category here. The wizard repurposes that very
        // term: replaceUncategorizedWithFirstCategory() renames it in place into
        // the first API category and nothing repoints the option, so excluding
        // it by id silently dropped a real, post-bearing category from the menu
        // of every generated site (#48). contai_category_is_unused_default()
        // keeps the original intent - hide a still-empty placeholder - without
        // that false assumption.
        $categories = get_categories([
            'hide_empty' => false,
        ]);

        $default_category_id = (int) get_option('default_category');
        $repurposed_category_id = (int) get_option(ContaiCategoryService::OPTION_REPURPOSED_DEFAULT, 0);

        // Always build and assign the primary "Main Navigation" menu, even
        // when no custom categories exist yet (#48). Without a menu assigned
        // to the theme's primary nav location, WordPress themes fall back to
        // wp_page_menu(), which lists published PAGES — i.e. the generated
        // legal pages — producing the reported "main menu shows only legal
        // pages, no categories" symptom. A Home-only menu still claims the
        // primary location and suppresses that fallback; category items are
        // appended whenever categories are present.
        $category_names = [];
        foreach ($categories as $cat) {
            $is_unused_default = contai_category_is_unused_default(
                (int) $cat->term_id,
                (int) $cat->count,
                $default_category_id,
                $repurposed_category_id
            );

            if ($is_unused_default) {
                continue;
            }

            $category_names[] = sanitize_text_field($cat->name);
        }

        $menuManager = new ContaiMainMenuManager();
        $menuManager->updateMainMenuWithCategories($category_names);
    }

    private function updateJobPayload(int $jobId, array $payload, int $currentStep, array $completedSteps): void
    {
        global $wpdb;

        $job = $this->jobRepository->findById($jobId);
        if (!$job) {
            return;
        }

        $nextStep = $currentStep + 1;
        $nextStepName = $this->steps[$nextStep] ?? null;

        $payload['progress'] = [
            'current_step' => $nextStep,
            'current_step_name' => $nextStepName,
            'completed_steps' => $completedSteps,
            'total_steps' => count($this->steps),
            'updated_at' => current_time('mysql')
        ];

        $job->setPayload($payload);
        $this->jobRepository->update($job);

        $table = $wpdb->prefix . 'contai_jobs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $table,
            ['processed_at' => current_time('mysql')],
            ['id' => $jobId],
            ['%s'],
            ['%d']
        );
    }

    private function updateJobPayloadWithoutAdvancing(int $jobId, array $payload, int $currentStep, array $completedSteps): void
    {
        global $wpdb;

        $job = $this->jobRepository->findById($jobId);
        if (!$job) {
            return;
        }

        $currentStepName = $this->steps[$currentStep] ?? null;

        $payload['progress'] = [
            'current_step' => $currentStep,
            'current_step_name' => $currentStepName,
            'completed_steps' => $completedSteps,
            'total_steps' => count($this->steps),
            'updated_at' => current_time('mysql')
        ];

        $job->setPayload($payload);
        $this->jobRepository->update($job);

        $table = $wpdb->prefix . 'contai_jobs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $table,
            ['processed_at' => current_time('mysql')],
            ['id' => $jobId],
            ['%s'],
            ['%d']
        );
    }

    private function markJobAsPending(int $jobId): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'contai_jobs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $table,
            ['status' => 'pending'],
            ['id' => $jobId],
            ['%s'],
            ['%d']
        );
    }

    public function getType()
    {
        return self::TYPE;
    }
}
