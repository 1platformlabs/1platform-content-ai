<?php

if (!defined('ABSPATH')) exit;

final class ContaiAnchorGenerator {

    private array $used_anchors = [];
    private bool $lowercase;
    private bool $hyphenate;

    public function __construct(bool $lowercase = true, bool $hyphenate = true) {
        $this->lowercase = $lowercase;
        $this->hyphenate = $hyphenate;
    }

    public function generate(string $text): string {
        $anchor = $this->sanitize($text);
        $anchor = $this->ensureUnique($anchor);

        $this->used_anchors[] = $anchor;

        return $anchor;
    }

    public function reset(): void {
        $this->used_anchors = [];
    }

    private function sanitize(string $text): string {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = wp_strip_all_tags($text);
        $text = $this->removeAccents($text);
        $text = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        if ($this->lowercase) {
            $text = strtolower($text);
        }

        $separator = $this->hyphenate ? '-' : '_';
        $text = preg_replace('/\s+/', $separator, $text);
        $text = preg_replace('/' . preg_quote($separator, '/') . '+/', $separator, $text);
        $text = trim($text, $separator);

        return !empty($text) ? $text : 'heading';
    }

    private function removeAccents(string $text): string {
        if (function_exists('remove_accents')) {
            return remove_accents($text);
        }

        $replacements = [
            'Ă ' => 'a', 'Ă¡' => 'a', 'Ă¢' => 'a', 'Ă£' => 'a', 'Ă¤' => 'a', 'Ă¥' => 'a',
            'Ă' => 'A', 'Ă' => 'A', 'Ă' => 'A', 'Ă' => 'A', 'Ă' => 'A', 'Ă
' => 'A',
            'Ă¨' => 'e', 'Ă©' => 'e', 'Ăª' => 'e', 'Ă«' => 'e',
            'Ă' => 'E', 'Ă' => 'E', 'Ă' => 'E', 'Ă' => 'E',
            'Ă¬' => 'i', 'Ă­' => 'i', 'Ă®' => 'i', 'Ă¯' => 'i',
            'Ă' => 'I', 'Ă' => 'I', 'Ă' => 'I', 'Ă' => 'I',
            'Ă²' => 'o', 'Ă³' => 'o', 'Ă´' => 'o', 'Ăµ' => 'o', 'Ă¶' => 'o', 'Ă¸' => 'o',
            'Ă' => 'O', 'Ă' => 'O', 'Ă' => 'O', 'Ă' => 'O', 'Ă' => 'O', 'Ă' => 'O',
            'Ă¹' => 'u', 'Ăº' => 'u', 'Ă»' => 'u', 'Ă¼' => 'u',
            'Ă' => 'U', 'Ă' => 'U', 'Ă' => 'U', 'Ă' => 'U',
            'Ă±' => 'n', 'Ă' => 'N',
            'Ă§' => 'c', 'Ă' => 'C',
        ];

        return strtr($text, $replacements);
    }

    private function ensureUnique(string $anchor): string {
        if (!in_array($anchor, $this->used_anchors, true)) {
            return $anchor;
        }

        $counter = 2;
        $separator = $this->hyphenate ? '-' : '_';

        while (in_array($anchor . $separator . $counter, $this->used_anchors, true)) {
            $counter++;
        }

        return $anchor . $separator . $counter;
    }
}
