<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/JobInterface.php';
require_once __DIR__ . '/../../database/repositories/KeywordRepository.php';
require_once __DIR__ . '/../../database/repositories/JobRepository.php';
require_once __DIR__ . '/../content/ContentGeneratorService.php';
require_once __DIR__ . '/../category/CategoryService.php';
require_once __DIR__ . '/../post/PostGenerationOrchestrator.php';
require_once __DIR__ . '/ContentGenerationPollingJob.php';

class ContaiPostGenerationJob implements ContaiJobInterface
{
    const TYPE = 'post_generation';

    private ContaiKeywordRepository $keyword_repository;
    private ContaiJobRepository $job_repository;
    private ContaiContentGeneratorService $content_service;
    private ContaiCategoryService $category_service;

    public function __construct()
    {
        $this->keyword_repository = new ContaiKeywordRepository();
        $this->job_repository = new ContaiJobRepository();
        $this->content_service = ContaiContentGeneratorService::create();
        $this->category_service = new ContaiCategoryService();
    }

    public function handle(array $payload)
    {
        // Fail-fast credit check before consuming API resources
        require_once __DIR__ . '/../billing/CreditGuard.php';
        $creditGuard = new ContaiCreditGuard();
        $creditCheck = $creditGuard->validateCredits();

        if (!$creditCheck['has_credits']) {
            $keyword = $this->loadKeyword($payload);
            $this->updateKeywordStatus($keyword, ContaiKeyword::STATUS_FAILED);
            throw new ContaiContentGenerationException(
                $creditCheck['message'],
                402
            );
        }

        $keyword = $this->loadKeyword($payload);

        $this->updateKeywordStatus($keyword, ContaiKeyword::STATUS_PROCESSING);

        try {
            $remote_job_id = $payload['remote_job_id'] ?? null;

            if (!$remote_job_id) {
                $remote_job_id = $this->createRemoteJob($keyword, $payload);
            }

            $this->enqueuePollingJob($remote_job_id, $keyword, $payload);
            $this->persistJobState($payload['job_id'] ?? null, $payload, $remote_job_id);

            return [
                'success' => true,
                'remote_job_id' => $remote_job_id,
                'keyword_id' => $keyword->getId(),
            ];
        } catch (ContaiContentGenerationException $e) {
            if ($e->isUnrecoverable()) {
                $this->updateKeywordStatus($keyword, ContaiKeyword::STATUS_FAILED);
                return [
                    'success' => true,
                    'post_id' => null,
                    'keyword_id' => $keyword->getId(),
                    'error' => $e->getMessage(), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                    'status_code' => $e->getStatusCode()
                ];
            }
            $this->updateKeywordStatus($keyword, ContaiKeyword::STATUS_PENDING);
            throw $e;
        } catch (Exception $e) {
            $this->updateKeywordStatus($keyword, ContaiKeyword::STATUS_PENDING);
            throw $e;
        }
    }

    public function getType()
    {
        return self::TYPE;
    }

    private function createRemoteJob(ContaiKeyword $keyword, array $payload): string
    {
        $category_names = $this->category_service->getAllCategoryNames();

        $result = $this->content_service->createRemoteContentJob(
            $keyword->getTitle(),
            $payload['lang'] ?? 'en',
            $payload['country'] ?? 'us',
            $payload['image_provider'] ?? 'pexels',
            $category_names
        );

        if (!$result['success']) {
            $status_code = $result['status_code'] ?? null;
            throw new ContaiContentGenerationException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                $result['message'] ?? 'Failed to create remote content job',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                $status_code
            );
        }

        return $result['job_id'];
    }

    private function enqueuePollingJob(string $remote_job_id, ContaiKeyword $keyword, array $payload): void
    {
        $polling_payload = [
            'keyword_id' => $keyword->getId(),
            'remote_job_id' => $remote_job_id,
            'lang' => $payload['lang'] ?? 'en',
            'country' => $payload['country'] ?? 'us',
            'image_provider' => $payload['image_provider'] ?? 'pexels',
            'post_created' => false,
            'poll_start_time' => time(),
            'poll_cycles' => 0,
        ];

        if (isset($payload['batch_id'])) {
            $polling_payload['batch_id'] = $payload['batch_id'];
        }

        $job = ContaiJob::create(
            ContaiContentGenerationPollingJob::TYPE,
            $polling_payload,
            $keyword->getVolume()
        );

        $created = $this->job_repository->create($job);

        if (!$created) {
            throw new RuntimeException('Failed to enqueue content generation polling job');
        }
    }

    private function persistJobState(?int $job_id, array $payload, string $remote_job_id): void
    {
        if (!$job_id) {
            return;
        }

        $job = $this->job_repository->findById($job_id);
        if (!$job) {
            return;
        }

        $current_payload = $job->getPayload();
        $current_payload['remote_job_id'] = $remote_job_id;
        $current_payload['polling_enqueued'] = true;

        $job->setPayload($current_payload);
        $this->job_repository->update($job);
    }

    private function loadKeyword(array $payload): ContaiKeyword
    {
        $keyword_id = $payload['keyword_id'] ?? null;

        if (!$keyword_id) {
            throw new InvalidArgumentException('keyword_id is required in payload');
        }

        $keyword = $this->keyword_repository->findById($keyword_id);

        if (!$keyword) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new RuntimeException("ContaiKeyword with ID {$keyword_id} not found");
        }

        return $keyword;
    }

    private function updateKeywordStatus(ContaiKeyword $keyword, string $status): void
    {
        $keyword->setStatus($status);
        $this->keyword_repository->update($keyword);
    }
}
