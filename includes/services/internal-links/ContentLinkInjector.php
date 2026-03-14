<?php

/**
 * Content Link Injector Service
 *
 * Responsible for injecting HTML links into post content at specific positions.
 * Handles content modification and link insertion with proper HTML structure.
 *
 * @package WPContentAI
 * @subpackage Services\InternalLinks
 */

namespace WPContentAI\Services\InternalLinks;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class ContaiContentLinkInjector
 *
 * Injects links into content at specified positions
 */
class ContaiContentLinkInjector
{
    /**
     * Inject links into content
     *
     * @param string $content Original content
     * @param array $link_data Array of link data [['match' => [...], 'url' => '...', 'title' => '...']]
     * @return string Modified content with links
     */
    public function injectLinks(string $content, array $link_data): string
    {
        if (empty($link_data)) {
            return $content;
        }

        usort($link_data, function ($a, $b) {
            return $b['match']['offset'] <=> $a['match']['offset'];
        });

        foreach ($link_data as $data) {
            $content = $this->injectSingleLink(
                $content,
                $data['match'],
                $data['url'],
                $data['title'] ?? ''
            );
        }

        return $content;
    }

    /**
     * Inject a single link into content
     *
     * @param string $content
     * @param array $match Match data with offset and length
     * @param string $url Target URL
     * @param string $title Link title
     * @return string
     */
    private function injectSingleLink(string $content, array $match, string $url, string $title = ''): string
    {
        $offset = $match['offset'];
        $length = $match['length'];
        $matched_text = $match['text'];

        $link_html = $this->buildLinkHtml($matched_text, $url, $title);

        $before = substr($content, 0, $offset);
        $after = substr($content, $offset + $length);
        return $before . $link_html . $after;
    }

    /**
     * Build HTML link element
     *
     * @param string $text Link text
     * @param string $url Link URL
     * @param string $title Link title attribute
     * @return string
     */
    private function buildLinkHtml(string $text, string $url, string $title = ''): string
    {
        $url = esc_url($url);
        $text = $text;

        if (!empty($title)) {
            $title = esc_attr($title);
            return '<a href="' . $url . '" title="' . $title . '">' . $text . '</a>';
        }

        return '<a href="' . $url . '">' . $text . '</a>';
    }

    /**
     * Inject multiple links for different keywords
     *
     * @param string $content
     * @param array $links_by_keyword Associative array [keyword => [link_data...]]
     * @return string
     */
    public function injectMultipleKeywordLinks(string $content, array $links_by_keyword): string
    {
        $all_links = [];

        foreach ($links_by_keyword as $keyword => $link_data_array) {
            foreach ($link_data_array as $link_data) {
                $all_links[] = $link_data;
            }
        }

        return $this->injectLinks($content, $all_links);
    }

    /**
     * Preview links without injecting (for testing/validation)
     *
     * @param string $content
     * @param array $link_data
     * @return array Preview data with snippets
     */
    public function previewLinks(string $content, array $link_data): array
    {
        $previews = [];

        foreach ($link_data as $data) {
            $match = $data['match'];
            $offset = $match['offset'];
            $length = $match['length'];

            $context_start = max(0, $offset - 50);
            $context_length = min(strlen($content) - $context_start, $length + 100);
            $context = substr($content, $context_start, $context_length);

            $previews[] = [
                'url' => $data['url'],
                'title' => $data['title'] ?? '',
                'matched_text' => $match['text'],
                'context' => $context,
                'position' => $offset,
                'link_html' => $this->buildLinkHtml($match['text'], $data['url'], $data['title'] ?? ''),
            ];
        }

        return $previews;
    }

    /**
     * Count total links that will be injected
     *
     * @param array $link_data
     * @return int
     */
    public function countLinks(array $link_data): int
    {
        return count($link_data);
    }

    /**
     * Validate link data structure
     *
     * @param array $link_data
     * @return bool
     * @throws \InvalidArgumentException
     */
    public function validateLinkData(array $link_data): bool
    {
        foreach ($link_data as $index => $data) {
            if (!isset($data['match']) || !is_array($data['match'])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new \InvalidArgumentException("Link data at index {$index} missing 'match' array");
            }

            if (!isset($data['match']['offset']) || !is_int($data['match']['offset'])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new \InvalidArgumentException("Link data at index {$index} missing valid 'offset'");
            }

            if (!isset($data['match']['length']) || !is_int($data['match']['length'])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new \InvalidArgumentException("Link data at index {$index} missing valid 'length'");
            }

            if (!isset($data['match']['text']) || !is_string($data['match']['text'])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new \InvalidArgumentException("Link data at index {$index} missing valid 'text'");
            }

            if (!isset($data['url']) || !is_string($data['url'])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new \InvalidArgumentException("Link data at index {$index} missing valid 'url'");
            }
        }

        return true;
    }

    /**
     * Remove links from content (for testing/reversal)
     *
     * @param string $content
     * @param string $url URL to remove links for
     * @return string
     */
    public function removeLinks(string $content, string $url): string
    {
        $escaped_url = preg_quote(esc_url($url), '/');
        $pattern = "/<a\s+[^>]*href=[\"']{$escaped_url}[\"'][^>]*>(.*?)<\/a>/i";

        return preg_replace($pattern, '$1', $content);
    }
}
