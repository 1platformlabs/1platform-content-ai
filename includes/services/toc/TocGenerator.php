<?php

if (!defined('ABSPATH')) exit;

final class ContaiTocGenerator {

    private ContaiHeadingParser $parser;
    private ContaiAnchorGenerator $anchor_generator;
    private ContaiTocBuilder $builder;
    private ContaiContentInjector $injector;
    private ContaiTocConfiguration $config;

    public function __construct(
        ContaiHeadingParser $parser,
        ContaiAnchorGenerator $anchor_generator,
        ContaiTocBuilder $builder,
        ContaiContentInjector $injector,
        ContaiTocConfiguration $config
    ) {
        $this->parser = $parser;
        $this->anchor_generator = $anchor_generator;
        $this->builder = $builder;
        $this->injector = $injector;
        $this->config = $config;
    }

    public function generate(string $content): array {
        $this->anchor_generator->reset();

        $headings = $this->parser->extract($content);

        if (empty($headings)) {
            return $this->emptyResult($content);
        }

        $headings = $this->parser->filterByLevels($headings, $this->config->getHeadingLevels());
        $headings = $this->parser->excludeByPatterns($headings, $this->config->getExcludePatterns());
        $headings = $this->parser->removeEmpty($headings);

        if (count($headings) < $this->config->getMinHeadings()) {
            return $this->emptyResult($content);
        }

        $headings = $this->enrichHeadings($headings);

        $toc_html = $this->buildTocHtml($headings);
        $content_with_anchors = $this->injector->injectAnchors($content, $headings);
        $final_content = $this->injector->injectToc($content_with_anchors, $toc_html, $this->config->getPosition());

        return [
            'content' => $final_content,
            'toc_html' => $toc_html,
            'headings_count' => count($headings),
        ];
    }

    private function enrichHeadings(array $headings): array {
        return array_map(function($heading) {
            $clean_text = $this->parser->cleanText($heading['text']);
            $anchor = $this->anchor_generator->generate($clean_text);

            return array_merge($heading, [
                'clean_text' => $clean_text,
                'anchor' => $anchor,
            ]);
        }, $headings);
    }

    private function buildTocHtml(array $headings): string {
        $list_html = $this->builder->build($headings);

        if (empty($list_html)) {
            return '';
        }

        return $this->wrapToc($list_html);
    }

    private function wrapToc(string $list_html): string {
        $theme = esc_attr($this->config->getTheme());
        $list_type = esc_attr($this->config->shouldNumberList() ? 'numbered' : 'bulleted');
        $hierarchy_class = esc_attr($this->config->shouldShowHierarchy() ? 'hierarchical' : 'flat');
        $hidden_class = $this->config->getInitialState() === 'hide' ? ' toc-hidden' : '';

        $html = '<div class="toc-container toc-theme-' . $theme . ' toc-' . $list_type . ' toc-' . $hierarchy_class . '">';

        if ($this->config->shouldShowTitle() || $this->config->shouldShowToggle()) {
            $html .= '<div class="toc-header">';

            if ($this->config->shouldShowTitle()) {
                $html .= '<h2 class="toc-title">' . esc_html($this->config->getTitle()) . '</h2>';
            }

            if ($this->config->shouldShowToggle()) {
                $expanded = $this->config->getInitialState() === 'show' ? 'true' : 'false';
                $html .= '<button class="toc-toggle" aria-expanded="' . esc_attr($expanded) . '" aria-label="' . esc_attr__('Toggle table of contents', '1platform-content-ai') . '">';
                $html .= '<span class="toc-toggle-icon"></span>';
                $html .= '</button>';
            }

            $html .= '</div>';
        }

        $html .= '<div class="toc-content' . esc_attr($hidden_class) . '">';
        $html .= $list_html;
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private function emptyResult(string $content): array {
        return [
            'content' => $content,
            'toc_html' => '',
            'headings_count' => 0,
        ];
    }
}
