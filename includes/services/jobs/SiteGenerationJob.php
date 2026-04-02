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

class ContaiSiteGenerationJob implements ContaiJobInterface
{
    const TYPE = 'site_generation';

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
                try {
                    $this->generateComments($config['comments'] ?? []);
                } catch (Exception $e) {
                    contai_log("Optional step 'generateComments' failed: " . $e->getMessage()); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }
                break;

            case 'setupSearchConsole':
                try {
                    $this->setupSearchConsole();
                } catch (Exception $e) {
                    contai_log("Optional step 'setupSearchConsole' failed: " . $e->getMessage()); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }
                break;

            case 'setupAdsManager':
                try {
                    $this->setupAdsManager($config['adsense']['publisher_id'] ?? '');
                } catch (Exception $e) {
                    contai_log("Optional step 'setupAdsManager' failed: " . $e->getMessage()); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }
                break;

            case 'setupNavigation':
                try {
                    $this->setupNavigation();
                } catch (Exception $e) {
                    contai_log("Optional step 'setupNavigation' failed: " . $e->getMessage()); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }
                break;
        }
        return $payload;
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
            throw new Exception('Timeout waiting for posts to complete after ' . round($elapsedTime / 60) . ' minutes');
        }

        $payload['wait_start_time'] = $waitStartTime;
        $payload['_wait_and_retry'] = true;
        $payload['_wait_message'] = "Waiting for posts: {$status['completed']}/{$status['total']} completed. Elapsed: " . round($elapsedTime / 60) . " min.";

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
        $categories = get_categories([
            'hide_empty' => false,
            'exclude'    => [get_option('default_category')],
        ]);

        if (empty($categories)) {
            return;
        }

        $category_names = array_map(function ($cat) {
            return sanitize_text_field($cat->name);
        }, $categories);

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
