<?php

if (!defined('ABSPATH')) exit;

final class ContaiTocBuilder {

    public function build(array $headings): string {
        if (empty($headings)) {
            return '';
        }

        $min_level = $this->findMinLevel($headings);

        return $this->buildRecursive($headings, 0, $min_level);
    }

    private function findMinLevel(array $headings): int {
        $levels = array_column($headings, 'level');
        return !empty($levels) ? min($levels) : 1;
    }

    private function buildRecursive(array $headings, int $index, int $current_level): string {
        $html = '<ul class="toc-list toc-list-' . esc_attr($current_level) . '">';
        $count = count($headings);

        while ($index < $count) {
            $heading = $headings[$index];

            if ($heading['level'] < $current_level) {
                break;
            }

            if ($heading['level'] === $current_level) {
                $html .= sprintf(
                    '<li class="toc-item toc-level-%d"><a href="#%s" class="toc-link">%s</a>',
                    esc_attr($heading['level']),
                    esc_attr($heading['anchor']),
                    esc_html($heading['clean_text'])
                );

                $index++;

                if ($index < $count && $headings[$index]['level'] > $current_level) {
                    $nested_html = $this->buildRecursive($headings, $index, $current_level + 1);
                    $html .= $nested_html;

                    while ($index < $count && $headings[$index]['level'] > $current_level) {
                        $index++;
                    }
                }

                $html .= '</li>';
                continue;
            }

            if ($heading['level'] > $current_level) {
                $nested_html = $this->buildRecursive($headings, $index, $current_level + 1);
                $html .= $nested_html;

                while ($index < $count && $headings[$index]['level'] >= $current_level + 1) {
                    $index++;
                }
            }
        }

        $html .= '</ul>';

        return $html;
    }
}
