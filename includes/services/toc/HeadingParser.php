<?php

if (!defined('ABSPATH')) exit;

final class ContaiHeadingParser {

    private const HEADING_PATTERN = '/<h([1-6])([^>]*)>(.*?)<\/h\1>/is';

    public function extract(string $content): array {
        if (empty($content)) {
            return [];
        }

        preg_match_all(self::HEADING_PATTERN, $content, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            return [];
        }

        return array_map(fn($match) => [
            'full_tag' => $match[0],
            'level' => (int) $match[1],
            'attributes' => trim($match[2]),
            'text' => $match[3],
        ], $matches);
    }

    public function filterByLevels(array $headings, array $allowed_levels): array {
        return array_filter(
            $headings,
            fn($heading) => in_array($heading['level'], $allowed_levels, true)
        );
    }

    public function excludeByPatterns(array $headings, array $patterns): array {
        if (empty($patterns)) {
            return $headings;
        }

        return array_filter($headings, function($heading) use ($patterns) {
            $text = $this->cleanText($heading['text']);

            foreach ($patterns as $pattern) {
                if ($this->matchesPattern($text, $pattern)) {
                    return false;
                }
            }

            return true;
        });
    }

    public function removeEmpty(array $headings): array {
        return array_filter(
            $headings,
            fn($heading) => !empty(trim($this->cleanText($heading['text'])))
        );
    }

    public function cleanText(string $text): string {
        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim($text);
    }

    private function matchesPattern(string $text, string $pattern): bool {
        $pattern = trim($pattern);

        if (empty($pattern)) {
            return false;
        }

        if (str_contains($pattern, '*')) {
            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
            return (bool) preg_match($regex, $text);
        }

        return strcasecmp($text, $pattern) === 0;
    }
}
