<?php

namespace WPContentAI\Services\InternalLinks;

if (!defined('ABSPATH')) {
    exit;
}

class ContaiOutgoingLinkStrategy extends ContaiLinkingStrategy
{
    protected function canProcess(int $postId): bool
    {
        return !$this->hasReachedMaxLinksAsSource($postId);
    }

    protected function processRelatedPosts(int $postId, array $relatedPosts): int
    {
        $sourceContent = get_post($postId)->post_content;
        $linksToAdd = [];

        foreach ($relatedPosts as $targetPost) {
            $targetLinks = $this->findLinksToTarget($postId, $sourceContent, $targetPost);
            $linksToAdd = array_merge($linksToAdd, $targetLinks);
        }

        if (empty($linksToAdd)) {
            return 0;
        }

        $maxLinks = $this->getRemainingLinksForSource($postId);
        $linksToAdd = array_slice($linksToAdd, 0, $maxLinks);

        return $this->saveLinksToPost($postId, null, $linksToAdd);
    }

    private function findLinksToTarget(int $sourcePostId, string $sourceContent, $targetPost): array
    {
        $targetPostId = $targetPost->ID;

        if ($this->hasReachedMaxLinksAsTarget($targetPostId)) {
            return [];
        }

        $keywords = $this->getKeywordsForPost($targetPostId);
        $availableKeywords = $this->filterAvailableKeywords($keywords, $sourcePostId, $targetPostId);

        if (empty($availableKeywords)) {
            return [];
        }

        return $this->findKeywordMatchesForLinks(
            $sourceContent,
            $availableKeywords,
            get_permalink($targetPostId),
            $targetPost->post_title,
            $targetPostId
        );
    }
}
