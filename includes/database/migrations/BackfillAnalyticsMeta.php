<?php

if (!defined('ABSPATH')) exit;

class ContaiBackfillAnalyticsMeta {

    /**
     * Backfill _1platform_ai_generated and _1platform_keyword post meta
     * from existing contai_keywords table data.
     */
    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'contai_keywords';

        // Verify table exists (fresh installs won't have it yet)
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return;
        }

        $batch_size = 100;
        $offset = 0;

        do {
            $keywords = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, keyword FROM {$table} WHERE post_id IS NOT NULL AND post_id > 0 LIMIT %d OFFSET %d",
                $batch_size,
                $offset
            ));

            if (empty($keywords)) {
                break;
            }

            foreach ($keywords as $kw) {
                $post_id = absint($kw->post_id);
                if (!get_post($post_id)) {
                    continue; // Skip orphaned references
                }

                // Only set if not already set (idempotent)
                if (!get_post_meta($post_id, '_1platform_ai_generated', true)) {
                    update_post_meta($post_id, '_1platform_ai_generated', '1');
                }
                if (!get_post_meta($post_id, '_1platform_keyword', true)) {
                    update_post_meta($post_id, '_1platform_keyword', sanitize_text_field($kw->keyword));
                }
                if (!metadata_exists('post', $post_id, '_1platform_cluster')) {
                    update_post_meta($post_id, '_1platform_cluster', '');
                }
            }

            $offset += $batch_size;
        } while (count($keywords) === $batch_size);
    }

    public function down(): void {
        // Cannot reliably undo — meta was already mixed with manually set values
    }
}
