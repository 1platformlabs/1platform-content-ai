<?php
/**
 * Internal Link Model
 *
 * Represents a link between two posts using a keyword.
 * Follows SOLID principles with clear single responsibility.
 *
 * @package WPContentAI
 * @subpackage ContaiDatabase\Models
 */

namespace WPContentAI\ContaiDatabase\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class ContaiInternalLink
 *
 * Represents an internal link relationship between posts
 */
class ContaiInternalLink {
    /**
     * Link status constants
     */
    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';

    /**
     * @var int|null Link ID
     */
    private $id;

    /**
     * @var int Source post ID (post containing the link)
     */
    private $source_post_id;

    /**
     * @var int Target post ID (post being linked to)
     */
    private $target_post_id;

    /**
     * @var int ContaiKeyword ID used for the link
     */
    private $keyword_id;

    /**
     * @var string Link status (active|inactive)
     */
    private $status;

    /**
     * @var string Creation timestamp
     */
    private $created_at;

    /**
     * @var string Last update timestamp
     */
    private $updated_at;

    /**
     * Constructor
     *
     * @param array $data Link data
     */
    public function __construct(array $data = []) {
        $this->id = $data['id'] ?? null;
        $this->source_post_id = $data['source_post_id'] ?? 0;
        $this->target_post_id = $data['target_post_id'] ?? 0;
        $this->keyword_id = $data['keyword_id'] ?? 0;
        $this->status = $data['status'] ?? self::STATUS_ACTIVE;
        $this->created_at = $data['created_at'] ?? current_time('mysql');
        $this->updated_at = $data['updated_at'] ?? current_time('mysql');
    }

    /**
     * Get link ID
     *
     * @return int|null
     */
    public function getId(): ?int {
        return $this->id;
    }

    /**
     * Set link ID
     *
     * @param int $id
     * @return self
     */
    public function setId(int $id): self {
        $this->id = $id;
        return $this;
    }

    /**
     * Get source post ID
     *
     * @return int
     */
    public function getSourcePostId(): int {
        return $this->source_post_id;
    }

    /**
     * Set source post ID
     *
     * @param int $source_post_id
     * @return self
     */
    public function setSourcePostId(int $source_post_id): self {
        $this->source_post_id = $source_post_id;
        return $this;
    }

    /**
     * Get target post ID
     *
     * @return int
     */
    public function getTargetPostId(): int {
        return $this->target_post_id;
    }

    /**
     * Set target post ID
     *
     * @param int $target_post_id
     * @return self
     */
    public function setTargetPostId(int $target_post_id): self {
        $this->target_post_id = $target_post_id;
        return $this;
    }

    /**
     * Get keyword ID
     *
     * @return int
     */
    public function getKeywordId(): int {
        return $this->keyword_id;
    }

    /**
     * Set keyword ID
     *
     * @param int $keyword_id
     * @return self
     */
    public function setKeywordId(int $keyword_id): self {
        $this->keyword_id = $keyword_id;
        return $this;
    }

    /**
     * Get status
     *
     * @return string
     */
    public function getStatus(): string {
        return $this->status;
    }

    /**
     * Set status
     *
     * @param string $status
     * @return self
     */
    public function setStatus(string $status): self {
        if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_INACTIVE])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }
        $this->status = $status;
        $this->updated_at = current_time('mysql');
        return $this;
    }

    /**
     * Check if link is active
     *
     * @return bool
     */
    public function isActive(): bool {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Activate link
     *
     * @return self
     */
    public function activate(): self {
        return $this->setStatus(self::STATUS_ACTIVE);
    }

    /**
     * Deactivate link
     *
     * @return self
     */
    public function deactivate(): self {
        return $this->setStatus(self::STATUS_INACTIVE);
    }

    /**
     * Get created at timestamp
     *
     * @return string
     */
    public function getCreatedAt(): string {
        return $this->created_at;
    }

    /**
     * Get updated at timestamp
     *
     * @return string
     */
    public function getUpdatedAt(): string {
        return $this->updated_at;
    }

    /**
     * Validate the link data
     *
     * @return bool
     * @throws \InvalidArgumentException
     */
    public function validate(): bool {
        if ($this->source_post_id <= 0) {
            throw new \InvalidArgumentException('Source post ID must be greater than 0');
        }

        if ($this->target_post_id <= 0) {
            throw new \InvalidArgumentException('Target post ID must be greater than 0');
        }

        if ($this->source_post_id === $this->target_post_id) {
            throw new \InvalidArgumentException('Source and target post IDs cannot be the same');
        }

        if ($this->keyword_id <= 0) {
            throw new \InvalidArgumentException('ContaiKeyword ID must be greater than 0');
        }

        return true;
    }

    /**
     * Convert to array
     *
     * @return array
     */
    public function toArray(): array {
        return [
            'id' => $this->id,
            'source_post_id' => $this->source_post_id,
            'target_post_id' => $this->target_post_id,
            'keyword_id' => $this->keyword_id,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
