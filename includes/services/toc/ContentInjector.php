<?php

if (!defined('ABSPATH')) exit;

final class ContaiContentInjector {

    public function injectAnchors(string $content, array $headings): string {
        if (empty($headings)) {
            return $content;
        }

        foreach ($headings as $heading) {
            $anchor_span = sprintf(
                '<span id="%s" class="toc-anchor"></span>',
                esc_attr($heading['anchor'])
            );

            $replacement = preg_replace(
                '/^(<h' . $heading['level'] . '[^>]*>)/i',
                '$1' . $anchor_span,
                $heading['full_tag']
            );

            $content = $this->safeReplace($heading['full_tag'], $replacement, $content);
        }

        return $content;
    }

    public function injectToc(string $content, string $toc_html, string $position): string {
        return match($position) {
            'before_first_heading' => $this->insertBeforeFirstHeading($content, $toc_html),
            'after_first_heading' => $this->insertAfterFirstHeading($content, $toc_html),
            'top' => $toc_html . $content,
            'bottom' => $content . $toc_html,
            default => $this->insertBeforeFirstHeading($content, $toc_html),
        };
    }

    private function insertBeforeFirstHeading(string $content, string $toc_html): string {
        if (preg_match('/<h[1-6][^>]*>/i', $content, $match, PREG_OFFSET_CAPTURE)) {
            $position = $match[0][1];
            return substr($content, 0, $position) . $toc_html . substr($content, $position);
        }

        return $toc_html . $content;
    }

    private function insertAfterFirstHeading(string $content, string $toc_html): string {
        if (preg_match('/<\/h[1-6]>/i', $content, $match, PREG_OFFSET_CAPTURE)) {
            $position = $match[0][1] + strlen($match[0][0]);
            return substr($content, 0, $position) . $toc_html . substr($content, $position);
        }

        return $toc_html . $content;
    }

    private function safeReplace(string $search, string $replace, string $subject): string {
        $pos = mb_strpos($subject, $search);

        if ($pos === false) {
            return $subject;
        }

        return mb_substr($subject, 0, $pos) . $replace . mb_substr($subject, $pos + mb_strlen($search));
    }
}
