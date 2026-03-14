<?php

if (!defined('ABSPATH')) exit;

class ContaiWordPressPostCreator {

    private const CUSTOM_FIELD_METATITLE = '_contai_metatitle';

    public function create(string $title, string $content, ?string $slug = null, ?string $post_date = null, ?string $metatitle = null): int {
        $post_data = [
            'post_title' => sanitize_text_field($title),
            'post_content' => $content,
            'post_status' => 'publish',
            'post_type' => 'post'
        ];

        if ($slug !== null) {
            $post_data['post_name'] = sanitize_title($slug);
        }

        if ($post_date !== null) {
            $post_data['post_date'] = $this->sanitizePostDate($post_date);
            $post_data['post_date_gmt'] = get_gmt_from_date($post_data['post_date']);
        }

        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new RuntimeException("Failed to create post: " . $post_id->get_error_message());
        }

        if ($metatitle !== null) {
            $this->saveMetatitle($post_id, $metatitle);
        }

        return $post_id;
    }

    private function sanitizePostDate(string $post_date): string {
        $timestamp = strtotime($post_date);

        if ($timestamp === false) {
            return current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function saveMetatitle(int $post_id, string $metatitle): void {
        update_post_meta($post_id, self::CUSTOM_FIELD_METATITLE, sanitize_text_field($metatitle));
    }

    public function assignCategory(int $post_id, int $category_id): void {
        wp_set_object_terms($post_id, [$category_id], 'category', false);
    }

    public function setFeaturedImage(int $post_id, int $attachment_id): void {
        set_post_thumbnail($post_id, $attachment_id);
    }

    public function saveMetadata(int $post_id, array $metadata): void {
        foreach ($metadata as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }
    }

    public function getPermalink(int $post_id): string {
        return get_permalink($post_id);
    }
}
