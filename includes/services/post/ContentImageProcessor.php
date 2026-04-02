<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/ImageUploader.php';

class ContaiContentImageProcessor {

    private ContaiImageUploader $uploader;

    public function __construct(ContaiImageUploader $uploader) {
        $this->uploader = $uploader;
    }

    public function process(string $content, array $images, string $alt_text = ''): string {
        if (!empty($images)) {
            $url_map = $this->buildUrlMap($content, $images, $alt_text);
            $content = $this->replaceUrls($content, $url_map);
        }

        if ($alt_text !== '') {
            $content = $this->ensureImgAltAttributes($content, $alt_text);
        }

        return $content;
    }

    private function buildUrlMap(string $content, array $images, string $alt_text): array {
        $url_map = [];

        foreach ($images as $image) {
            $external_url = $image['url'] ?? null;

            if (empty($external_url) || !$this->isUrlInContent($content, $external_url)) {
                continue;
            }

            $image_alt = !empty($image['alt_text']) ? $image['alt_text'] : $alt_text;
            $attachment_id = $this->uploader->uploadFromUrl($external_url, $image_alt);

            if ($attachment_id === null) {
                continue;
            }

            $local_url = $this->uploader->getAttachmentUrl($attachment_id);

            if ($local_url) {
                $url_map[$external_url] = $local_url;
            }
        }

        return $url_map;
    }

    private function isUrlInContent(string $content, string $url): bool {
        return strpos($content, $url) !== false;
    }

    private function replaceUrls(string $content, array $url_map): string {
        foreach ($url_map as $external_url => $local_url) {
            $content = str_replace($external_url, $local_url, $content);
        }

        return $content;
    }

    private function ensureImgAltAttributes(string $content, string $alt_text): string {
        $escaped_alt = esc_attr($alt_text);

        // Replace empty alt attributes with the keyword-based alt text
        $content = preg_replace(
            '/<img([^>]*)\salt=["\']["\']/',
            '<img$1 alt="' . $escaped_alt . '"',
            $content
        );

        // Add alt attribute to <img> tags missing it entirely
        $content = preg_replace(
            '/<img((?![^>]*\salt=)[^>]*)\s*\/?>/',
            '<img$1 alt="' . $escaped_alt . '">',
            $content
        );

        return $content;
    }
}
