<?php
/**
 * ContaiKeyword Matcher Service
 *
 * Handles keyword matching with word boundaries and case-insensitive options.
 * Uses optimized regex patterns for finding keyword occurrences in content.
 *
 * @package WPContentAI
 * @subpackage Services\InternalLinks
 */

namespace WPContentAI\Services\InternalLinks;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class ContaiKeywordMatcher
 *
 * Responsible for matching keywords in content with configurable options
 */
class ContaiKeywordMatcher {
    /**
     * @var bool Use case-insensitive matching
     */
    private $case_insensitive;

    /**
     * @var bool Use word boundaries
     */
    private $word_boundaries;

    /**
     * @var int Minimum keyword length
     */
    private $min_length;

    /**
     * @var array Excluded HTML tags
     */
    private $excluded_tags;

    /**
     * Constructor
     *
     * @param bool $case_insensitive
     * @param bool $word_boundaries
     * @param int $min_length
     * @param array $excluded_tags
     */
    public function __construct(
        bool $case_insensitive = true,
        bool $word_boundaries = true,
        int $min_length = 3,
        array $excluded_tags = []
    ) {
        $this->case_insensitive = $case_insensitive;
        $this->word_boundaries = $word_boundaries;
        $this->min_length = $min_length;
        $this->excluded_tags = $excluded_tags;
    }

    /**
     * Find all occurrences of a keyword in content
     *
     * @param string $content The content to search in
     * @param string $keyword The keyword to find
     * @return array Array of match positions with offset and length
     */
    public function findMatches(string $content, string $keyword): array {
        if (strlen($keyword) < $this->min_length) {
            return [];
        }

        $masked_content = $this->maskExcludedTags($content);
        $pattern = $this->buildPattern($keyword);
        $matches = [];

        preg_match_all($pattern, $masked_content, $results, PREG_OFFSET_CAPTURE);

        if (!empty($results[0])) {
            foreach ($results[0] as $match) {
                $offset = $match[1];
                $matched_text = $match[0];

                if (!$this->isWithinMaskedRegion($offset, $masked_content)) {
                    $matches[] = [
                        'offset' => $offset,
                        'length' => strlen($matched_text),
                        'text' => $matched_text,
                    ];
                }
            }
        }

        return $matches;
    }

    /**
     * Build regex pattern for keyword matching
     *
     * @param string $keyword
     * @return string
     */
    private function buildPattern(string $keyword): string {
        $escaped = preg_quote($keyword, '/');

        $boundary_start = $this->word_boundaries ? '\b' : '';
        $boundary_end = $this->word_boundaries ? '\b' : '';

        $modifiers = $this->case_insensitive ? 'iu' : 'u';

        return "/{$boundary_start}{$escaped}{$boundary_end}/{$modifiers}";
    }

    /**
     * Mask excluded HTML tags to prevent matching within them
     *
     * @param string $content
     * @return string
     */
    private function maskExcludedTags(string $content): string {
        if (empty($this->excluded_tags)) {
            return $content;
        }

        $tags_pattern = implode('|', array_map('preg_quote', $this->excluded_tags));
        $pattern = "/<({$tags_pattern})\b[^>]*>.*?<\/\1>/is";

        return preg_replace_callback($pattern, function($matches) {
            return str_repeat('█', strlen($matches[0]));
        }, $content);
    }

    /**
     * Check if position is within a masked region
     *
     * @param int $offset
     * @param string $masked_content
     * @return bool
     */
    private function isWithinMaskedRegion(int $offset, string $masked_content): bool {
        if ($offset >= strlen($masked_content)) {
            return false;
        }

        return $masked_content[$offset] === '█';
    }

    /**
     * Count total matches for a keyword
     *
     * @param string $content
     * @param string $keyword
     * @return int
     */
    public function countMatches(string $content, string $keyword): int {
        return count($this->findMatches($content, $keyword));
    }

    /**
     * Find first match of a keyword
     *
     * @param string $content
     * @param string $keyword
     * @return array|null
     */
    public function findFirstMatch(string $content, string $keyword): ?array {
        $matches = $this->findMatches($content, $keyword);
        return !empty($matches) ? $matches[0] : null;
    }

    /**
     * Check if content contains keyword
     *
     * @param string $content
     * @param string $keyword
     * @return bool
     */
    public function hasMatch(string $content, string $keyword): bool {
        return $this->countMatches($content, $keyword) > 0;
    }

    /**
     * Get configuration
     *
     * @return array
     */
    public function getConfig(): array {
        return [
            'case_insensitive' => $this->case_insensitive,
            'word_boundaries' => $this->word_boundaries,
            'min_length' => $this->min_length,
            'excluded_tags' => $this->excluded_tags,
        ];
    }
}
