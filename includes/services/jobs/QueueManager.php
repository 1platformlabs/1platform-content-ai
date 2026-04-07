<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../database/repositories/JobRepository.php';
require_once __DIR__ . '/../../database/repositories/KeywordRepository.php';
require_once __DIR__ . '/../../database/models/Job.php';
require_once __DIR__ . '/PostGenerationJob.php';

class ContaiQueueManager
{
    private $jobRepository;
    private $keywordRepository;

    public function __construct()
    {
        $this->jobRepository = new ContaiJobRepository();
        $this->keywordRepository = new ContaiKeywordRepository();
    }

    public function enqueuePostGeneration($count, array $config = [])
    {
        if ($count < 1) {
            throw new InvalidArgumentException('Count must be at least 1');
        }

        $defaultConfig = [
            'lang' => 'en',
            'country' => 'us',
            'image_provider' => 'pexels',
        ];

        $config = array_merge($defaultConfig, $config);

        $enqueuedCount = 0;

        for ($i = 0; $i < $count; $i++) {
            $keyword = $this->getNextPendingKeyword();

            if (!$keyword) {
                break;
            }

            $payload = [
                'keyword_id' => $keyword->getId(),
                'lang' => $config['lang'],
                'country' => $config['country'],
                'image_provider' => $config['image_provider'],
            ];

            if (isset($config['batch_id'])) {
                $payload['batch_id'] = $config['batch_id'];
            }

            $job = ContaiJob::create(
                ContaiPostGenerationJob::TYPE,
                $payload,
                $keyword->getVolume()
            );

            if ($this->jobRepository->create($job)) {
                $this->keywordRepository->updateStatus($keyword->getId(), 'active');
                $enqueuedCount++;
            }
        }

        if ($enqueuedCount > 0) {
            contai_trigger_immediate_job_processing();
        }

        return $enqueuedCount;
    }

    public function getPendingCount()
    {
        return $this->jobRepository->countPendingJobs();
    }

    public function getProcessingCount()
    {
        return $this->jobRepository->countProcessingJobs();
    }

    public function getProcessingJobsWithKeywords()
    {
        return $this->jobRepository->getProcessingJobsWithKeywords();
    }

    public function clearPendingJobs()
    {
        return $this->jobRepository->deletePendingJobs();
    }

    public function clearAllJobs()
    {
        $activeKeywordIds = $this->jobRepository->getActiveJobKeywordIds();

        $deletedCount = $this->jobRepository->deleteAllActiveJobs();

        if (!empty($activeKeywordIds)) {
            $this->keywordRepository->resetActiveKeywordsByIds($activeKeywordIds);
        }

        return $deletedCount;
    }

    public function getQueueStatus()
    {
        return [
            'pending' => $this->getPendingCount(),
            'processing' => $this->getProcessingCount(),
            'processing_jobs' => $this->getProcessingJobsWithKeywords()
        ];
    }

    private function getNextPendingKeyword()
    {
        $activeKeywordIds = $this->jobRepository->getActiveJobKeywordIds();
        $keywords = $this->keywordRepository->findByStatus('pending', 100);

        if (empty($keywords)) {
            return null;
        }

        $availableKeywords = array_filter($keywords, function($keyword) use ($activeKeywordIds) {
            return !in_array($keyword->getId(), $activeKeywordIds, true);
        });

        if (empty($availableKeywords)) {
            return null;
        }

        usort($availableKeywords, function($a, $b) {
            return $b->getVolume() - $a->getVolume();
        });

        // Skip keywords that already have generated posts (re-execution deduplication)
        foreach ($availableKeywords as $keyword) {
            $existing_post = get_posts([
                'meta_key'    => '_1platform_keyword',
                'meta_value'  => $keyword->getKeyword(),
                'post_type'   => 'post',
                'post_status' => ['publish', 'draft'],
                'numberposts' => 1,
            ]);

            if (empty($existing_post)) {
                return $keyword;
            }

            // Mark keyword as done so it's not re-checked
            $this->keywordRepository->updateStatus($keyword->getId(), 'done');
            error_log("[ContAI] Keyword '{$keyword->getKeyword()}' already has a post — marked as done");
        }

        return null;
    }
}
