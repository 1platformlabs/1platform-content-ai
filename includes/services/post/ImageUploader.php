<?php

if (!defined('ABSPATH')) exit;

class ContaiImageUploader {

    public function __construct() {
        $this->ensureMediaFunctionsLoaded();
    }

    public function uploadFromUrl(string $image_url, string $alt_text = ''): ?int {
        $temp_file = download_url($image_url);

        if (is_wp_error($temp_file)) {
            contai_log("Failed to download image from {$image_url}: " . $temp_file->get_error_message());
            return null;
        }

        $attachment_id = $this->createAttachment($temp_file, $image_url);

        $this->cleanupTempFile($temp_file);

        if ($attachment_id !== null && $alt_text !== '') {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
        }

        return $attachment_id;
    }

    public function getAttachmentUrl(int $attachment_id): ?string {
        return wp_get_attachment_url($attachment_id);
    }

    private function createAttachment(string $temp_file, string $image_url): ?int {
        $file_array = [
            'name' => basename(wp_parse_url($image_url, PHP_URL_PATH)),
            'tmp_name' => $temp_file
        ];

        $attachment_id = media_handle_sideload($file_array, 0);

        if (is_wp_error($attachment_id)) {
            contai_log("Failed to upload image {$image_url}: " . $attachment_id->get_error_message());
            return null;
        }

        return $attachment_id;
    }

    private function cleanupTempFile(string $temp_file): void {
        if (!is_wp_error($temp_file) && file_exists($temp_file)) {
            wp_delete_file($temp_file);
        }
    }

    private function ensureMediaFunctionsLoaded(): void {
        if (!function_exists('download_url')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        if (!function_exists('media_handle_sideload')) {
            require_once(ABSPATH . 'wp-admin/includes/media.php');
        }
        if (!function_exists('wp_read_image_metadata')) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
    }
}
