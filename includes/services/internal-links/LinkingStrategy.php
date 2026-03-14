<?php

namespace WPContentAI\Services\InternalLinks;

use WPContentAI\ContaiDatabase\Repositories\ContaiInternalLinkRepository;

if (!defined('ABSPATH')) {
    exit;
}

abstract class ContaiLinkingStrategy
{
    protected $link_repository;
    protected $keyword_repository;
    protected $keyword_matcher;
    protected $distribution_strategy;
    protected $link_injector;
    protected $config;

    public function __construct(
        ContaiInternalLinkRepository $link_repository,
        \ContaiKeywordRepository $keyword_repository,
        ContaiKeywordMatcher $keyword_matcher,
        ContaiLinkDistributionStrategy $distribution_strategy,
        ContaiContentLinkInjector $link_injector,
        \ContaiConfig $config
    ) {
        $this->link_repository = $link_repository;
        $this->keyword_repository = $keyword_repository;
        $this->keyword_matcher = $keyword_matcher;
        $this->distribution_strategy = $distribution_strategy;
        $this->link_injector = $link_injector;
        $this->config = $config;
    }

    public function execute(int $postId): int
    {
        if (!$this->canProcess($postId)) {
            return 0;
        }

        $categoryId = $this->getPostCategoryId($postId);
        $relatedPosts = $this->getPostsInCategory($categoryId, $postId);

        if (empty($relatedPosts)) {
            return 0;
        }

        return $this->processRelatedPosts($postId, $relatedPosts);
    }

    abstract protected function canProcess(int $postId): bool;

    abstract protected function processRelatedPosts(int $postId, array $relatedPosts): int;

    protected function getKeywordsForPost(int $postId): array
    {
        return $this->keyword_repository->findByPostId($postId);
    }

    protected function filterAvailableKeywords(array $keywords, int $sourcePostId, int $targetPostId): array
    {
        $available = [];

        foreach ($keywords as $keyword) {
            $keywordId = $keyword->getId();

            if ($this->link_repository->linkExists($sourcePostId, $targetPostId, $keywordId)) {
                continue;
            }

            if (!$this->canAddMoreLinksForKeyword($keywordId)) {
                continue;
            }

            $available[] = $keyword;
        }

        return $available;
    }

    protected function findKeywordMatchesForLinks(
        string $content,
        array $keywords,
        string $targetUrl,
        string $targetTitle,
        ?int $targetPostId = null
    ): array {
        $links = [];

        foreach ($keywords as $keyword) {
            $matches = $this->keyword_matcher->findMatches($content, $keyword->getKeyword());
            if (empty($matches)) {
                continue;
            }

            $remainingForKeyword = $this->getRemainingLinksForKeyword($keyword->getId());
            if ($remainingForKeyword <= 0) {
                continue;
            }

            $selectedMatches = $this->distribution_strategy->selectDistributedMatches(
                $matches,
                min($remainingForKeyword, 1),
                $content
            );

            foreach ($selectedMatches as $match) {
                $linkData = [
                    'match' => $match,
                    'url' => $targetUrl,
                    'title' => $targetTitle,
                    'keyword_id' => $keyword->getId(),
                ];

                if ($targetPostId !== null) {
                    $linkData['target_post_id'] = $targetPostId;
                }

                $links[] = $linkData;
            }
        }

        return $links;
    }

    protected function saveLinksToPost(int $sourcePostId, ?int $fixedTargetPostId, array $linksToAdd): int
    {
        if (empty($linksToAdd)) {
            return 0;
        }

        if (!$this->updatePostContentWithLinks($sourcePostId, $linksToAdd)) {
            return 0;
        }

        $savedCount = 0;
        foreach ($linksToAdd as $linkData) {
            $targetPostId = $fixedTargetPostId ?? $linkData['target_post_id'];
            if ($this->saveLinkRecord($sourcePostId, $targetPostId, $linkData['keyword_id'])) {
                $savedCount++;
            }
        }

        return $savedCount;
    }

    protected function hasReachedMaxLinksAsSource(int $postId): bool
    {
        return $this->link_repository->countBySourcePost($postId) >= $this->config->getMaxLinksPerPost();
    }

    protected function hasReachedMaxLinksAsTarget(int $postId): bool
    {
        return $this->link_repository->countByTargetPost($postId) >= $this->config->getMaxLinksPerTarget();
    }

    protected function getRemainingLinksForKeyword(int $keywordId): int
    {
        return $this->config->getMaxLinksPerKeyword() - $this->link_repository->countByKeyword($keywordId);
    }

    protected function canAddMoreLinksForKeyword(int $keywordId): bool
    {
        return $this->getRemainingLinksForKeyword($keywordId) > 0;
    }

    protected function getRemainingLinksForSource(int $postId): int
    {
        return $this->config->getMaxLinksPerPost() - $this->link_repository->countBySourcePost($postId);
    }

    private function getPostCategoryId(int $postId): int
    {
        $categories = wp_get_post_categories($postId);
        return !empty($categories) ? $categories[0] : 0;
    }

    private function getPostsInCategory(int $categoryId, int $excludePostId): array
    {
        // phpcs:disable WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Excluding current post from linking candidates; small result set.
        return get_posts([
            'category' => $categoryId,
            'post_status' => 'publish',
            'post_type' => 'post',
            'posts_per_page' => $this->config->getInternalLinksBatchSize(),
            'exclude' => [$excludePostId],
        ]);
        // phpcs:enable WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
    }

    private function updatePostContentWithLinks(int $postId, array $linksToAdd): bool
    {
        if (empty($linksToAdd)) {
            return true;
        }

        if (!$this->acquirePostLock($postId)) {
            return false;
        }

        $freshPost = get_post($postId);
        if (!$freshPost) {
            $this->releasePostLock($postId);
            return false;
        }

        $modifiedContent = $this->link_injector->injectLinks($freshPost->post_content, $linksToAdd);

        $result = wp_update_post([
            'ID' => $postId,
            'post_content' => $modifiedContent,
        ], true);

        $this->releasePostLock($postId);

        return !is_wp_error($result);
    }

    private function acquirePostLock(int $postId, int $timeout = 5): bool
    {
        global $wpdb;
        $lockName = "contai_post_lock_{$postId}";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, %d)", $lockName, $timeout)) == 1;
    }

    private function releasePostLock(int $postId): bool
    {
        global $wpdb;
        $lockName = "contai_post_lock_{$postId}";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lockName)) == 1;
    }

    private function saveLinkRecord(int $sourcePostId, int $targetPostId, int $keywordId): bool
    {
        if ($this->link_repository->linkExists($sourcePostId, $targetPostId, $keywordId)) {
            return false;
        }

        $link = new \WPContentAI\ContaiDatabase\Models\ContaiInternalLink([
            'source_post_id' => $sourcePostId,
            'target_post_id' => $targetPostId,
            'keyword_id' => $keywordId,
            'status' => \WPContentAI\ContaiDatabase\Models\ContaiInternalLink::STATUS_ACTIVE,
        ]);

        return $this->link_repository->create($link) !== false;
    }
}
