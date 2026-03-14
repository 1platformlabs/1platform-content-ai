<?php

if (!defined('ABSPATH')) exit;

class ContaiKeyword {

    private ?int $id = null;
    private string $keyword;
    private ?string $original_keyword = null;
    private string $title;
    private ?string $original_title = null;
    private int $volume;
    private string $url;
    private ?string $post_url = null;
    private ?int $post_id = null;
    private ?int $category_id = null;
    private string $status;
    private ?string $created_at = null;
    private ?string $updated_at = null;
    private ?string $deleted_at = null;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    public function __construct(array $data = []) {
        if (!empty($data)) {
            $this->fillFromArray($data);
        }
    }

    public function fillFromArray(array $data): void {
        $this->id = isset($data['id']) ? (int) $data['id'] : null;
        $this->keyword = $data['keyword'] ?? '';
        $this->original_keyword = $data['original_keyword'] ?? null;
        $this->title = $data['title'] ?? '';
        $this->original_title = $data['original_title'] ?? null;
        $this->volume = isset($data['volume']) ? (int) $data['volume'] : 0;
        $this->url = $data['url'] ?? '';
        $this->post_url = $data['post_url'] ?? null;
        $this->post_id = isset($data['post_id']) ? (int) $data['post_id'] : null;
        $this->category_id = isset($data['category_id']) ? (int) $data['category_id'] : null;
        $this->status = $data['status'] ?? self::STATUS_PENDING;
        $this->created_at = $data['created_at'] ?? null;
        $this->updated_at = $data['updated_at'] ?? null;
        $this->deleted_at = $data['deleted_at'] ?? null;
    }

    public function toArray(): array {
        return [
            'id' => $this->id,
            'keyword' => $this->keyword,
            'original_keyword' => $this->original_keyword,
            'title' => $this->title,
            'original_title' => $this->original_title,
            'volume' => $this->volume,
            'url' => $this->url,
            'post_url' => $this->post_url,
            'post_id' => $this->post_id,
            'category_id' => $this->category_id,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }

    public function toDbArray(): array {
        $data = [
            'keyword' => $this->keyword,
            'original_keyword' => $this->original_keyword,
            'title' => $this->title,
            'original_title' => $this->original_title,
            'volume' => $this->volume,
            'url' => $this->url,
            'post_url' => $this->post_url,
            'post_id' => $this->post_id,
            'category_id' => $this->category_id,
            'status' => $this->status,
        ];

        if ($this->id !== null) {
            $data['id'] = $this->id;
        }

        return $data;
    }

    public function validate(): array {
        $errors = [];

        if (empty($this->keyword)) {
            $errors[] = 'ContaiKeyword is required';
        }

        if ($this->volume < 0) {
            $errors[] = 'Volume must be a positive number';
        }

        if (!in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_DONE, self::STATUS_INACTIVE, self::STATUS_PENDING, self::STATUS_PROCESSING, self::STATUS_FAILED], true)) {
            $errors[] = 'Invalid status';
        }

        return $errors;
    }

    public function isValid(): bool {
        return empty($this->validate());
    }

    public function getId(): ?int {
        return $this->id;
    }

    public function setId(?int $id): void {
        $this->id = $id;
    }

    public function getKeyword(): string {
        return $this->keyword;
    }

    public function setKeyword(string $keyword): void {
        $this->keyword = sanitize_text_field($keyword);
    }

    public function getOriginalKeyword(): ?string {
        return $this->original_keyword;
    }

    public function setOriginalKeyword(?string $original_keyword): void {
        $this->original_keyword = $original_keyword !== null ? sanitize_text_field($original_keyword) : null;
    }

    public function getTitle(): string {
        return $this->title;
    }

    public function setTitle(string $title): void {
        $this->title = sanitize_text_field($title);
    }

    public function getOriginalTitle(): ?string {
        return $this->original_title;
    }

    public function setOriginalTitle(?string $original_title): void {
        $this->original_title = $original_title !== null ? sanitize_text_field($original_title) : null;
    }

    public function getVolume(): int {
        return $this->volume;
    }

    public function setVolume(int $volume): void {
        $this->volume = max(0, $volume);
    }

    public function getUrl(): string {
        return $this->url;
    }

    public function setUrl(string $url): void {
        $this->url = esc_url_raw($url);
    }

    public function getPostUrl(): ?string {
        return $this->post_url;
    }

    public function setPostUrl(?string $post_url): void {
        $this->post_url = $post_url !== null ? esc_url_raw($post_url) : null;
    }

    public function getPostId(): ?int {
        return $this->post_id;
    }

    public function setPostId(?int $post_id): void {
        $this->post_id = $post_id !== null && $post_id > 0 ? $post_id : null;
    }

    public function getCategoryId(): ?int {
        return $this->category_id;
    }

    public function setCategoryId(?int $category_id): void {
        $this->category_id = $category_id !== null && $category_id > 0 ? $category_id : null;
    }

    public function getStatus(): string {
        return $this->status;
    }

    public function setStatus(string $status): void {
        if (in_array($status, [self::STATUS_ACTIVE, self::STATUS_DONE, self::STATUS_INACTIVE, self::STATUS_PENDING, self::STATUS_PROCESSING, self::STATUS_FAILED], true)) {
            $this->status = $status;
        }
    }

    public function getCreatedAt(): ?string {
        return $this->created_at;
    }

    public function getUpdatedAt(): ?string {
        return $this->updated_at;
    }

    public function getDeletedAt(): ?string {
        return $this->deleted_at;
    }

    public function isDeleted(): bool {
        return $this->deleted_at !== null;
    }

    public function isDone(): bool {
        return $this->status === self::STATUS_DONE && !$this->isDeleted();
    }
}
