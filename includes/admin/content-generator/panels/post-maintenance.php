<?php

if (!defined('ABSPATH')) exit;

class ContaiPostMaintenancePanel {

    private int $dates_randomized = 0;
    private int $thumbnails_updated = 0;
    private bool $action_executed = false;
    private string $action_type = '';
    private string $error_message = '';

    public function __construct() {
        $this->handle_form_submissions();
    }

    private function handle_form_submissions(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified below via check_admin_referer().
        if (!isset($_POST['contai_randomize_dates']) && !isset($_POST['contai_update_thumbnails'])) {
            return;
        }

        check_admin_referer('contai_post_maintenance', 'contai_maintenance_nonce');

        if (!current_user_can('manage_options')) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
        if (isset($_POST['contai_randomize_dates'])) {
            $this->action_type = 'randomize_dates';
            $this->randomize_post_dates();
        } else {
            $this->action_type = 'update_thumbnails';
            $this->update_all_post_thumbnails();
        }

        $this->action_executed = true;
    }

    public function render(): void {
        $this->render_notices();
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
        <?php
    }

    private function render_notices(): void {
        if (!$this->action_executed) {
            return;
        }

        if (!empty($this->error_message)) {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo esc_html($this->error_message); ?></p>
            </div>
            <?php
            return;
        }

        if ($this->action_type === 'randomize_dates') {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php
                printf(
                    /* translators: %d: number of posts with randomized dates */
                    esc_html__('Successfully randomized dates for %d posts.', '1platform-content-ai'),
                    intval($this->dates_randomized)
                ); ?></p>
            </div>
            <?php
        } elseif ($this->action_type === 'update_thumbnails') {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php
                printf(
                    /* translators: %d: number of posts with updated thumbnails */
                    esc_html__('Successfully updated thumbnails for %d posts.', '1platform-content-ai'),
                    intval($this->thumbnails_updated)
                ); ?></p>
            </div>
            <?php
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
            $this->error_message = __('No published posts found to randomize.', '1platform-content-ai');
            return;
        }

        foreach ($post_ids as $post_id) {
            $days_ago = wp_rand(0, 365);
            $hours = wp_rand(0, 23);
            $minutes = wp_rand(0, 59);
            $seconds = wp_rand(0, 59);
            $timestamp = strtotime("-{$days_ago} days");
            $timestamp = mktime($hours, $minutes, $seconds, (int) gmdate('n', $timestamp), (int) gmdate('j', $timestamp), (int) gmdate('Y', $timestamp));

            $local_date = wp_date('Y-m-d H:i:s', $timestamp);
            $gmt_date = get_gmt_from_date($local_date);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $wpdb->posts,
                [
                    'post_date'         => $local_date,
                    'post_date_gmt'     => $gmt_date,
                    'post_modified'     => $local_date,
                    'post_modified_gmt' => $gmt_date,
                ],
                ['ID' => $post_id],
                ['%s', '%s', '%s', '%s'],
                ['%d']
            );

            clean_post_cache((int) $post_id);
        }

        $this->dates_randomized = count($post_ids);
    }

    private function update_all_post_thumbnails(): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $post_ids = $wpdb->get_col("
            SELECT ID FROM {$wpdb->posts}
            WHERE post_type = 'post' AND post_status = 'publish'
        ");

        if (empty($post_ids)) {
            $this->error_message = __('No published posts found to update.', '1platform-content-ai');
            return;
        }

        foreach ($post_ids as $post_id) {
            if ($this->set_random_image_as_thumbnail((int) $post_id)) {
                $this->thumbnails_updated++;
            }
        }
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

        $random_image_url = esc_url_raw($random_image_url);
        if (empty($random_image_url) || !wp_http_validate_url($random_image_url)) {
            return false;
        }

        $attachment_id = media_sideload_image($random_image_url, $post_id, null, 'id');
        if (is_wp_error($attachment_id)) {
            return false;
        }

        set_post_thumbnail($post_id, $attachment_id);
        return true;
    }
}
