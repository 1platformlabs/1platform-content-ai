<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/ImageUploader.php';

class ContaiContentImageProcessor {

    private ContaiImageUploader $uploader;

    public function __construct(ContaiImageUploader $uploader) {
        $this->uploader = $uploader;
    }

    public function process(string $content, array $images): string {
        if (empty($images)) {
            return $content;
        }

        $url_map = $this->buildUrlMap($content, $images);

        return $this->replaceUrls($content, $url_map);
    }

    private function buildUrlMap(string $content, array $images): array {
        $url_map = [];

        foreach ($images as $image) {
            $external_url = $image['url'] ?? null;

            if (empty($external_url) || !$this->isUrlInContent($content, $external_url)) {
                continue;
            }

            $attachment_id = $this->uploader->uploadFromUrl($external_url);

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
}
