<?php

if (!defined('ABSPATH')) exit;

class ContaiPostMaintenancePanel {

    public function render(): void {
        ?>
        <div class="contai-settings-panel contai-panel-maintenance">
            <div class="contai-panel-body">
                <div class="contai-maintenance-grid">
                    <div class="contai-maintenance-item">
                        <div class="contai-maintenance-header">
                            <h3><?php esc_html_e('Randomize Post Dates', '1platform-content-ai'); ?></h3>
                        </div>
                        <p class="contai-help-text">
                            <span class="dashicons dashicons-info"></span>
                            <?php esc_html_e('Randomly distribute publication dates across the last 365 days for all posts', '1platform-content-ai'); ?>
                        </p>
                        <form method="post" class="contai-maintenance-form">
                            <?php wp_nonce_field('contai_post_maintenance', 'contai_maintenance_nonce'); ?>
                            <button type="submit" name="contai_randomize_dates" class="button button-secondary contai-button-action">
                                <span class="dashicons dashicons-randomize"></span>
                                <span class="contai-button-text"><?php esc_html_e('Randomize Dates', '1platform-content-ai'); ?></span>
                            </button>
                        </form>
                    </div>

                    <div class="contai-maintenance-item">
                        <div class="contai-maintenance-header">
                            <h3><?php esc_html_e('Update Thumbnails', '1platform-content-ai'); ?></h3>
                        </div>
                        <p class="contai-help-text">
                            <span class="dashicons dashicons-info"></span>
                            <?php esc_html_e('Extract and set featured images from post content for all posts', '1platform-content-ai'); ?>
                        </p>
                        <form method="post" class="contai-maintenance-form">
                            <?php wp_nonce_field('contai_post_maintenance', 'contai_maintenance_nonce'); ?>
                            <button type="submit" name="contai_update_thumbnails" class="button button-secondary contai-button-action">
                                <span class="dashicons dashicons-format-image"></span>
                                <span class="contai-button-text"><?php esc_html_e('Update Thumbnails', '1platform-content-ai'); ?></span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <?php $this->process_actions(); ?>
        <?php
    }

    private function process_actions(): void {
        if (isset($_POST['contai_randomize_dates']) || isset($_POST['contai_update_thumbnails'])) {
            check_admin_referer('contai_post_maintenance', 'contai_maintenance_nonce');

            if (!current_user_can('manage_options')) {
                return;
            }

            if (isset($_POST['contai_randomize_dates'])) {
                $this->randomize_post_dates();
            } elseif (isset($_POST['contai_update_thumbnails'])) {
                $this->update_all_post_thumbnails();
            }
        }
    }

    private function randomize_post_dates(): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $post_ids = $wpdb->get_col("
            SELECT ID FROM {$wpdb->posts}
            WHERE post_type = 'post' AND post_status = 'publish'
        ");

        if (empty($post_ids)) {
            $this->render_info(__('No published posts found to randomize.', '1platform-content-ai'));
            return;
        }

        foreach ($post_ids as $post_id) {
            $days_ago = wp_rand(0, 365);
            $timestamp = strtotime("-{$days_ago} days");
            $random_date = gmdate('Y-m-d H:i:s', $timestamp);
            $random_date_gmt = get_gmt_from_date($random_date);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $wpdb->posts,
                [
                    'post_date' => $random_date,
                    'post_date_gmt' => $random_date_gmt,
                    'post_modified' => $random_date,
                    'post_modified_gmt' => $random_date_gmt,
                ],
                ['ID' => $post_id],
                ['%s', '%s', '%s', '%s'],
                ['%d']
            );
        }

        $this->render_success(
            sprintf(
                /* translators: %d: number of posts with randomized dates */
                __('✓ Successfully randomized dates for %d posts.', '1platform-content-ai'),
                count($post_ids)
            )
        );
    }

    private function update_all_post_thumbnails(): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $post_ids = $wpdb->get_col("
            SELECT ID FROM {$wpdb->posts}
            WHERE post_type = 'post' AND post_status = 'publish'
        ");

        if (empty($post_ids)) {
            $this->render_info(__('No published posts found to update.', '1platform-content-ai'));
            return;
        }

        $updated_count = 0;
        foreach ($post_ids as $post_id) {
            if ($this->set_random_image_as_thumbnail($post_id)) {
                $updated_count++;
            }
        }

        $this->render_success(
            sprintf(
                /* translators: %d: number of posts with updated thumbnails */
                __('✓ Successfully updated thumbnails for %d posts.', '1platform-content-ai'),
                $updated_count
            )
        );
    }

    private function set_random_image_as_thumbnail(int $post_id): bool {
        $content = get_post_field('post_content', $post_id);
        if (empty($content)) {
            return false;
        }

        preg_match_all('/<img.*?src=["\'](.*?)["\'].*?>/', $content, $matches);

        if (empty($matches[1])) {
            return false;
        }

        $random_image_url = $matches[1][array_rand($matches[1])];
        if (empty($random_image_url)) {
            return false;
        }

        $attachment_id = media_sideload_image($random_image_url, $post_id, null, 'id');
        if (is_wp_error($attachment_id)) {
            return false;
        }

        set_post_thumbnail($post_id, $attachment_id);
        return true;
    }

    private function render_success(string $message): void {
        ?>
        <div class="contai-notice contai-notice-success">
            <span class="dashicons dashicons-yes-alt"></span>
            <p><?php echo wp_kses_post($message); ?></p>
        </div>
        <?php
    }

    private function render_info(string $message): void {
        ?>
        <div class="contai-notice contai-notice-info">
            <span class="dashicons dashicons-info"></span>
            <p><?php echo wp_kses_post($message); ?></p>
        </div>
        <?php
    }
}
