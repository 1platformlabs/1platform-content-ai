<?php

namespace WPContentAI\Services\InternalLinks;

use WPContentAI\ContaiDatabase\Models\ContaiInternalLink;
use WPContentAI\ContaiDatabase\Repositories\ContaiInternalLinkRepository;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../../database/models/InternalLink.php';
require_once __DIR__ . '/../../database/repositories/InternalLinkRepository.php';
require_once __DIR__ . '/../../database/repositories/KeywordRepository.php';
require_once __DIR__ . '/KeywordMatcher.php';
require_once __DIR__ . '/LinkDistributionStrategy.php';
require_once __DIR__ . '/ContentLinkInjector.php';
require_once __DIR__ . '/LinkingStrategy.php';
require_once __DIR__ . '/IncomingLinkStrategy.php';
require_once __DIR__ . '/OutgoingLinkStrategy.php';
require_once __DIR__ . '/../../services/config/Config.php';

class ContaiInternalLinkProcessor
{
    private $link_repository;
    private $config;
    private $incomingStrategy;
    private $outgoingStrategy;

    public function __construct()
    {
        $this->link_repository = new ContaiInternalLinkRepository();
        $this->config = \ContaiConfig::getInstance();

        $dependencies = $this->createDependencies();

        $this->incomingStrategy = new ContaiIncomingLinkStrategy(...$dependencies);
        $this->outgoingStrategy = new ContaiOutgoingLinkStrategy(...$dependencies);
    }

    private function createDependencies(): array
    {
        $keywordRepository = new \ContaiKeywordRepository();

        $keywordMatcher = new ContaiKeywordMatcher(
            $this->config->isCaseInsensitiveMatching(),
            $this->config->useWordBoundaries(),
            $this->config->getMinKeywordLength(),
            $this->config->getExcludedTags()
        );

        $distributionStrategy = new ContaiLinkDistributionStrategy(200);
        $linkInjector = new ContaiContentLinkInjector();

        return [
            $this->link_repository,
            $keywordRepository,
            $keywordMatcher,
            $distributionStrategy,
            $linkInjector,
            $this->config,
        ];
    }

    public function processNewPost(int $post_id, int $job_id = null): array
    {
        if (!$this->config->isInternalLinksEnabled()) {
            return ['success' => false, 'message' => 'Internal links disabled'];
        }

        $post = get_post($post_id);
        if (!$this->isValidPost($post)) {
            return ['success' => false, 'message' => 'Invalid post'];
        }

        $linksToNewPost = $this->incomingStrategy->execute($post_id);
        $linksFromNewPost = $this->outgoingStrategy->execute($post_id);

        return [
            'success' => true,
            'links_to_new_post' => $linksToNewPost,
            'links_from_new_post' => $linksFromNewPost,
            'total_links' => $linksToNewPost + $linksFromNewPost,
        ];
    }

    private function isValidPost($post): bool
    {
        return $post && $post->post_status === 'publish' && $post->post_type === 'post';
    }

    public function removeLinksForPost(int $postId): bool
    {
        return $this->link_repository->deleteByPost($postId);
    }

    public function getStatistics(): array
    {
        return [
            'total_links' => $this->link_repository->countAll(),
            'active_links' => $this->link_repository->countAll(ContaiInternalLink::STATUS_ACTIVE),
            'inactive_links' => $this->link_repository->countAll(ContaiInternalLink::STATUS_INACTIVE),
        ];
    }
}
