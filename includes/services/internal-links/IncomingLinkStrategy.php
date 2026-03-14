<?php

namespace WPContentAI\Services\InternalLinks;

if (!defined('ABSPATH')) {
    exit;
}

class ContaiIncomingLinkStrategy extends ContaiLinkingStrategy
{
    protected function canProcess(int $postId): bool
    {
        $keywords = $this->getKeywordsForPost($postId);
        return !empty($keywords);
    }

    protected function processRelatedPosts(int $postId, array $relatedPosts): int
    {
        $keywords = $this->getKeywordsForPost($postId);
        $targetUrl = get_permalink($postId);
        $targetTitle = get_post($postId)->post_title;

        $linksAdded = 0;

        foreach ($relatedPosts as $sourcePost) {
            $linksAdded += $this->processSourcePost($sourcePost, $postId, $keywords, $targetUrl, $targetTitle);
        }

        return $linksAdded;
    }

    private function processSourcePost($sourcePost, int $targetPostId, array $keywords, string $targetUrl, string $targetTitle): int
    {
        $sourcePostId = $sourcePost->ID;

        if ($this->hasReachedMaxLinksAsSource($sourcePostId)) {
            return 0;
        }

        $availableKeywords = $this->filterAvailableKeywords($keywords, $sourcePostId, $targetPostId);
        if (empty($availableKeywords)) {
            return 0;
        }

        $linksToAdd = $this->findKeywordMatchesForLinks(
            $sourcePost->post_content,
            $availableKeywords,
            $targetUrl,
            $targetTitle
        );

        if (empty($linksToAdd)) {
            return 0;
        }

        $maxRemaining = $this->getRemainingLinksForSource($sourcePostId);
        $linksToAdd = array_slice($linksToAdd, 0, $maxRemaining);

        return $this->saveLinksToPost($sourcePostId, $targetPostId, $linksToAdd);
    }
}
