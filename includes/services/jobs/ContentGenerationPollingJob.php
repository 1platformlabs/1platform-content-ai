<?php
/**
 * Async content generation polling job.
 *
 * Polls the remote API every 5 seconds to check if the content generation
 * job has completed. When completed, creates the WordPress post using the
 * returned content. Re-enqueues itself via ['continue' => true] until the
 * remote job finishes or the timeout is reached.
 *
 * cURL examples (import to Postman):
 *
 * # Create async content generation job (done by ContaiPostGenerationJob):
 * curl -X POST https://api-qa.1platform.pro/api/v1/posts/content/ \
 *   -H "Content-Type: application/json" \
 *   -H "Authorization: Bearer <APP_ACCESS_TOKEN>" \
 *   -H "x-user-token: <USER_ACCESS_TOKEN>" \
 *   -d '{"keyword":"animales extintos","lang":"es","country":"es","image_provider":"pexels","categories":["animales","animales-extintos"]}'
 *
 * # Poll content generation job status (done by this polling job):
 * curl -X GET https://api-qa.1platform.pro/api/v1/posts/content/jobs/<JOB_ID> \
 *   -H "Authorization: Bearer <APP_ACCESS_TOKEN>" \
 *   -H "x-user-token: <USER_ACCESS_TOKEN>"
 *
 * Completed response example:
 * {"success":true,"data":{"status":"completed","result":{"title":"...","content":"<html>...","images":[],"seo_metadata":{},"category":"...","url":"..."}}}
 *
 * Failed response example:
 * {"success":true,"data":{"status":"failed","error":"generation error message"}}
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/JobInterface.php';
require_once __DIR__ . '/../../database/repositories/JobRepository.php';
require_once __DIR__ . '/../../database/repositories/KeywordRepository.php';
require_once __DIR__ . '/../content/ContentGeneratorService.php';
require_once __DIR__ . '/../post/PostGenerationOrchestrator.php';

class ContaiContentGenerationPollingJob implements ContaiJobInterface
{
    const TYPE = 'content_generation_polling';

    const POLLS_PER_CYCLE = 10;
    const POLL_INTERVAL_SECONDS = 5;
    const MAX_POLL_TIME_SECONDS = 1800;

    private ContaiContentGeneratorService $content_service;
    private ContaiPostGenerationOrchestrator $orchestrator;
    private ContaiKeywordRepository $keyword_repository;
    private ContaiJobRepository $job_repository;

    public function __construct()
    {
        $this->content_service = ContaiContentGeneratorService::create();
        $this->orchestrator = ContaiPostGenerationOrchestrator::create();
        $this->keyword_repository = new ContaiKeywordRepository();
        $this->job_repository = new ContaiJobRepository();
    }

    public function handle(array $payload)
    {
        $remote_job_id = $payload['remote_job_id'] ?? null;
        $keyword_id = $payload['keyword_id'] ?? null;
        $job_id = $payload['job_id'] ?? null;

        if (!$remote_job_id || !$keyword_id) {
            throw new InvalidArgumentException('remote_job_id and keyword_id are required in polling payload');
        }

        if (!empty($payload['post_created'])) {
            return ['success' => true, 'message' => 'Post already created by previous execution'];
        }

        $keyword = $this->keyword_repository->findById($keyword_id);
        if (!$keyword) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new RuntimeException("ContaiKeyword with ID {$keyword_id} not found");
        }

        if ($keyword->getStatus() === ContaiKeyword::STATUS_DONE) {
            return ['success' => true, 'message' => 'ContaiKeyword already processed'];
        }

        $poll_start_time = $payload['poll_start_time'] ?? time();
        $elapsed = time() - $poll_start_time;

        if ($elapsed > self::MAX_POLL_TIME_SECONDS) {
            $this->updateKeywordStatus($keyword, ContaiKeyword::STATUS_FAILED);
            contai_log("Polling timeout for remote job {$remote_job_id} after {$elapsed}s");
            return [
                'success' => true,
                'message' => "Polling timeout after {$elapsed}s for remote job {$remote_job_id}",
            ];
        }

        for ($i = 0; $i < self::POLLS_PER_CYCLE; $i++) {
            $poll_result = $this->pollRemoteJob($remote_job_id);

            if ($poll_result === null) {
                if ($i < self::POLLS_PER_CYCLE - 1) {
                    sleep(self::POLL_INTERVAL_SECONDS);
                }
                continue;
            }

            $status = $poll_result['status'] ?? 'unknown';

            if ($status === 'completed') {
                return $this->handleCompleted($keyword, $payload, $poll_result, $job_id);
            }

            if ($status === 'failed') {
                return $this->handleFailed($keyword, $poll_result, $remote_job_id);
            }

            if ($i < self::POLLS_PER_CYCLE - 1) {
                sleep(self::POLL_INTERVAL_SECONDS);
            }
        }

        return $this->requeueForNextCycle($job_id, $payload, $poll_start_time);
    }

    public function getType()
    {
        return self::TYPE;
    }

    private function pollRemoteJob(string $remote_job_id): ?array
    {
        try {
            $result = $this->content_service->getRemoteJobStatus($remote_job_id);

            if (!$result['success']) {
                contai_log("Poll failed for remote job {$remote_job_id}: " . ($result['message'] ?? 'unknown error'));
                return null;
            }

            return $result;
        } catch (Exception $e) {
            contai_log("Poll exception for remote job {$remote_job_id}: " . $e->getMessage()); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            return null;
        }
    }

    private function handleCompleted(ContaiKeyword $keyword, array $payload, array $poll_result, ?int $job_id): array
    {
        $api_result = $poll_result['result'] ?? [];

        if (empty($api_result)) {
            $this->updateKeywordStatus($keyword, ContaiKeyword::STATUS_FAILED);
            return ['success' => true, 'message' => 'Remote job completed but returned empty result'];
        }

        try {
            $generation_params = [
                'lang' => $payload['lang'] ?? 'en',
                'country' => $payload['country'] ?? 'us',
                'image_provider' => $payload['image_provider'] ?? 'pexels',
            ];

            $result = $this->orchestrator->createPostFromApiResult($keyword, $generation_params, $api_result);

            $keyword->setPostId($result->getPostId());
            $keyword->setPostUrl($result->getPostUrl());
            if ($result->getCategoryId() !== null) {
                $keyword->setCategoryId($result->getCategoryId());
            }
            $this->keyword_repository->update($keyword);
            $this->updateKeywordStatus($keyword, ContaiKeyword::STATUS_DONE);

            $this->markPostCreated($job_id, $payload);

            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
            do_action('contai_post_generation_completed', $result->getPostId(), $keyword->getId(), $payload);

            return [
                'success' => true,
                'post_id' => $result->getPostId(),
                'keyword_id' => $keyword->getId(),
            ];
        } catch (ContaiContentGenerationException $e) {
            if ($e->isUnrecoverable()) {
                $this->updateKeywordStatus($keyword, ContaiKeyword::STATUS_FAILED);
                return [
                    'success' => true,
                    'keyword_id' => $keyword->getId(),
                    'error' => $e->getMessage(), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                ];
            }
            $this->updateKeywordStatus($keyword, ContaiKeyword::STATUS_PENDING);
            throw $e;
        }
    }

    private function handleFailed(ContaiKeyword $keyword, array $poll_result, string $remote_job_id): array
    {
        $error = $poll_result['error'] ?? 'Remote job failed without error details';
        $this->updateKeywordStatus($keyword, ContaiKeyword::STATUS_FAILED);
        contai_log("Remote content job {$remote_job_id} failed: {$error}");

        return [
            'success' => true,
            'keyword_id' => $keyword->getId(),
            'error' => $error,
        ];
    }

    private function requeueForNextCycle(?int $job_id, array $payload, int $poll_start_time): array
    {
        if (!$job_id) {
            throw new RuntimeException('Cannot re-queue polling job: job_id missing from payload');
        }

        $job = $this->job_repository->findById($job_id);
        if (!$job) {
            throw new RuntimeException("Cannot re-queue polling job: job {$job_id} not found");
        }

        $payload['poll_start_time'] = $poll_start_time;
        $payload['poll_cycles'] = ($payload['poll_cycles'] ?? 0) + 1;

        $job->setPayload($payload);
        $job->setStatus(ContaiJobStatus::PENDING);
        $job->setProcessedAt(current_time('mysql'));
        $this->job_repository->update($job);

        return ['continue' => true];
    }

    private function markPostCreated(?int $job_id, array $payload): void
    {
        if (!$job_id) {
            return;
        }

        $job = $this->job_repository->findById($job_id);
        if (!$job) {
            return;
        }

        $payload['post_created'] = true;
        $job->setPayload($payload);
        $this->job_repository->update($job);
    }

    private function updateKeywordStatus(ContaiKeyword $keyword, string $status): void
    {
        $keyword->setStatus($status);
        $this->keyword_repository->update($keyword);
    }
}
