<?php

if (!defined('ABSPATH')) exit;

final class ContaiTocConfiguration {

    private const OPTION_KEY = 'contai_toc_config';

    private const DEFAULTS = [
        'enabled' => true,
        'post_types' => ['post', 'page'],
        'heading_levels' => [2, 3, 4],
        'min_headings' => 4,
        'position' => 'before_first_heading',
        'title' => 'Table of Contents',
        'show_title' => true,
        'show_toggle' => true,
        'initial_state' => 'show',
        'show_hierarchy' => true,
        'numbered_list' => true,
        'exclude_patterns' => [],
        'theme' => 'grey',
        'lowercase_anchors' => true,
        'hyphenate_anchors' => true,
        'smooth_scroll' => true,
    ];

    public function get(string $key, $default = null) {
        $config = get_option(self::OPTION_KEY, self::DEFAULTS);

        if (!isset($config[$key])) {
            return $default ?? self::DEFAULTS[$key] ?? null;
        }

        return $config[$key];
    }

    public function getAll(): array {
        return array_merge(self::DEFAULTS, get_option(self::OPTION_KEY, []));
    }

    public function update(array $data): bool {
        $current = $this->getAll();
        $merged = array_merge($current, $data);
        $sanitized = $this->sanitize($merged);

        return update_option(self::OPTION_KEY, $sanitized);
    }

    public function isEnabled(): bool {
        return (bool) $this->get('enabled');
    }

    public function getPostTypes(): array {
        return (array) $this->get('post_types');
    }

    public function getHeadingLevels(): array {
        return (array) $this->get('heading_levels');
    }

    public function getMinHeadings(): int {
        return (int) $this->get('min_headings');
    }

    public function getPosition(): string {
        return (string) $this->get('position');
    }

    public function getTitle(): string {
        return (string) $this->get('title');
    }

    public function shouldShowTitle(): bool {
        return (bool) $this->get('show_title');
    }

    public function shouldShowToggle(): bool {
        return (bool) $this->get('show_toggle');
    }

    public function getInitialState(): string {
        return (string) $this->get('initial_state');
    }

    public function shouldShowHierarchy(): bool {
        return (bool) $this->get('show_hierarchy');
    }

    public function shouldNumberList(): bool {
        return (bool) $this->get('numbered_list');
    }

    public function getExcludePatterns(): array {
        return (array) $this->get('exclude_patterns');
    }

    public function getTheme(): string {
        return (string) $this->get('theme');
    }

    public function shouldLowercaseAnchors(): bool {
        return (bool) $this->get('lowercase_anchors');
    }

    public function shouldHyphenateAnchors(): bool {
        return (bool) $this->get('hyphenate_anchors');
    }

    public function shouldSmoothScroll(): bool {
        return (bool) $this->get('smooth_scroll');
    }

    public function reset(): bool {
        return update_option(self::OPTION_KEY, self::DEFAULTS);
    }

    private function sanitize(array $data): array {
        return [
            'enabled' => !empty($data['enabled']),
            'post_types' => $this->sanitizePostTypes($data['post_types'] ?? []),
            'heading_levels' => $this->sanitizeHeadingLevels($data['heading_levels'] ?? []),
            'min_headings' => max(1, intval($data['min_headings'] ?? 4)),
            'position' => $this->sanitizePosition($data['position'] ?? 'before_first_heading'),
            'title' => sanitize_text_field($data['title'] ?? 'Table of Contents'),
            'show_title' => !empty($data['show_title']),
            'show_toggle' => !empty($data['show_toggle']),
            'initial_state' => in_array($data['initial_state'] ?? 'show', ['show', 'hide']) ? $data['initial_state'] : 'show',
            'show_hierarchy' => !empty($data['show_hierarchy']),
            'numbered_list' => !empty($data['numbered_list']),
            'exclude_patterns' => $this->sanitizeExcludePatterns($data['exclude_patterns'] ?? []),
            'theme' => $this->sanitizeTheme($data['theme'] ?? 'grey'),
            'lowercase_anchors' => !empty($data['lowercase_anchors']),
            'hyphenate_anchors' => !empty($data['hyphenate_anchors']),
            'smooth_scroll' => !empty($data['smooth_scroll']),
        ];
    }

    private function sanitizePostTypes(array $types): array {
        $available_types = get_post_types(['public' => true], 'names');
        return array_values(array_intersect($types, $available_types));
    }

    private function sanitizeHeadingLevels(array $levels): array {
        $valid = array_filter($levels, fn($level) => is_numeric($level) && $level >= 1 && $level <= 6);
        return array_values(array_unique(array_map('intval', $valid)));
    }

    private function sanitizePosition(string $position): string {
        $valid = ['before_first_heading', 'after_first_heading', 'top', 'bottom'];
        return in_array($position, $valid, true) ? $position : 'before_first_heading';
    }

    private function sanitizeExcludePatterns($patterns): array {
        if (is_string($patterns)) {
            $patterns = array_filter(array_map('trim', explode("\n", $patterns)));
        }

        if (!is_array($patterns)) {
            return [];
        }

        return array_values(array_map('sanitize_text_field', $patterns));
    }

    private function sanitizeTheme(string $theme): string {
        $valid = ['grey', 'light-blue', 'white', 'black', 'transparent'];
        return in_array($theme, $valid, true) ? $theme : 'grey';
    }
}
