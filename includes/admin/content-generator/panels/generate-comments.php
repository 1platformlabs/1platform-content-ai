<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../../services/comments/CommentsService.php';

class ContaiGenerateCommentsPanel {

    private array $generated_comments = [];
    private bool $comments_generated = false;
    private int $posts_processed = 0;
    private int $posts_failed = 0;
    private array $creditCheck = ['has_credits' => true];

    public function __construct() {
        $this->handle_form_submissions();
    }

    private function handle_form_submissions(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified below via check_admin_referer().
        if (isset($_POST['contai_generate_now'])) {
            check_admin_referer('contai_comments_nonce', 'contai_comments_nonce');

            if (!current_user_can('manage_options')) {
                add_settings_error(
                    'contai_comments',
                    'no_permission',
                    __('You do not have permission to perform this action.', '1platform-content-ai'),
                    'error'
                );
                return;
            }

            $this->handle_comment_generation();
        }
    }

    private function handle_comment_generation(): void {
        // Validate credits before generating comments
        require_once __DIR__ . '/../../../services/billing/CreditGuard.php';
        $creditGuard = new ContaiCreditGuard();
        $creditCheck = $creditGuard->validateCredits();

        if (!$creditCheck['has_credits']) {
            add_settings_error(
                'contai_comments',
                'insufficient_credits',
                $creditCheck['message'],
                'error'
            );
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_form_submissions() via check_admin_referer().
        $num_posts = isset($_POST['contai_num_posts']) ? absint($_POST['contai_num_posts']) : 10;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_form_submissions() via check_admin_referer().
        $comments_per_post = isset($_POST['contai_comments_per_post']) ? absint($_POST['contai_comments_per_post']) : 1;

        $num_posts = max(1, min(100, $num_posts));
        $comments_per_post = max(1, min(10, $comments_per_post));

        $posts = get_posts([
            'numberposts' => $num_posts,
            'orderby'     => 'rand',
            'post_type'   => 'post',
            'post_status' => 'publish',
        ]);

        if (empty($posts)) {
            add_settings_error(
                'contai_comments',
                'no_posts',
                __('No published posts found to generate comments for.', '1platform-content-ai'),
                'error'
            );
            return;
        }

        $this->generated_comments = $this->generate_comments_for_posts($posts, $comments_per_post);
        $this->comments_generated = true;
    }

    private function generate_comments_for_posts(array $posts, int $comments_per_post): array {
        $service = ContaiCommentsService::create();
        $lang = ContaiCommentsService::getSiteLang();
        $website_topic = get_option('contai_site_theme', '');
        $comments = [];

        foreach ($posts as $post) {
            $context = ContaiCommentsService::buildContext($website_topic, $post->post_title);
            $result = $service->generateComments($comments_per_post, $lang, $context);

            if (!$result['success']) {
                contai_log(sprintf(
                    '[WPContentAI] Comments generation failed for post #%d (%s): %s',
                    $post->ID,
                    $post->post_title,
                    $result['error']
                ));
                $this->posts_failed++;
                continue;
            }

            $this->posts_processed++;

            foreach ($result['comments'] as $api_comment) {
                $full_name = sanitize_text_field($api_comment['full_name'] ?? '');
                $content = sanitize_textarea_field($api_comment['content'] ?? '');

                if (empty($full_name) || empty($content)) {
                    continue;
                }

                $comment_date = $this->generate_random_past_date();
                $email = $this->generate_email_from_name($full_name);

                wp_insert_comment([
                    'comment_post_ID'      => $post->ID,
                    'comment_author'       => $full_name,
                    'comment_author_email' => $email,
                    'comment_author_url'   => '',
                    'comment_content'      => $content,
                    'comment_approved'     => 1,
                    'comment_date'         => $comment_date,
                    'comment_date_gmt'     => get_gmt_from_date($comment_date),
                    'user_id'              => 0,
                ]);

                $comments[] = [
                    'url'     => get_permalink($post->ID),
                    'title'   => $post->post_title,
                    'name'    => $full_name,
                    'email'   => $email,
                    'comment' => $content,
                ];
            }
        }

        return $comments;
    }

    private function generate_random_past_date(): string {
        $timestamp = wp_rand(strtotime('-1 year'), time());
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function generate_email_from_name(string $name): string {
        $normalized = strtolower(str_replace(' ', '.', $name));
        $normalized = preg_replace('/[^a-z0-9.]/', '', $normalized);
        return $normalized . '@example.com';
    }

    public function render(): void {
        $this->render_notices();
        settings_errors('contai_comments');

        $creditGuard = new ContaiCreditGuard();
        $this->creditCheck = $creditGuard->validateCredits();

        if ( ! $this->creditCheck['has_credits'] ) : ?>
            <div class="notice notice-warning" style="margin-bottom: 15px;">
                <p>
                    <strong><?php esc_html_e( 'Insufficient Balance', '1platform-content-ai' ); ?></strong> —
                    <?php
                    printf(
                        /* translators: %1$s: balance amount, %2$s: currency code */
                        esc_html__( 'Your balance is %1$s %2$s. Add credits to generate comments.', '1platform-content-ai' ),
                        esc_html( number_format( $this->creditCheck['balance'], 2 ) ),
                        esc_html( $this->creditCheck['currency'] )
                    );
                    ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=contai-billing' ) ); ?>">
                        <?php esc_html_e( 'Add Credits', '1platform-content-ai' ); ?>
                    </a>
                </p>
            </div>
        <?php endif;

        $this->render_generation_form();

        if ($this->comments_generated && !empty($this->generated_comments)) {
            $this->render_results_table();
        }
    }

    private function render_notices(): void {
        if (!$this->comments_generated) {
            return;
        }

        $total = count($this->generated_comments);

        if ($total > 0): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php
                printf(
                    /* translators: %1$d: number of comments generated, %2$d: number of posts processed */
                    esc_html__('Successfully generated %1$d comments across %2$d posts.', '1platform-content-ai'),
                    intval($total),
                    intval($this->posts_processed)
                ); ?></p>
            </div>
        <?php endif;

        if ($this->posts_failed > 0): ?>
            <div class="notice notice-warning is-dismissible">
                <p><?php
                printf(
                    /* translators: %d: number of posts that failed comment generation */
                    esc_html__('Failed to generate comments for %d post(s). Check the error log for details.', '1platform-content-ai'),
                    intval($this->posts_failed)
                ); ?></p>
            </div>
        <?php endif;
    }

    private function render_generation_form(): void {
        ?>
        <div class="contai-panel">
            <div class="contai-panel-head">
                <div class="contai-panel-head-main">
                    <h2 class="contai-panel-title">
                        <span class="dashicons dashicons-admin-comments"></span>
                        <?php esc_html_e('Generate Comments', '1platform-content-ai'); ?>
                    </h2>
                    <p class="contai-panel-desc">
                        <?php esc_html_e('Create AI-powered comments for your blog posts', '1platform-content-ai'); ?>
                    </p>
                </div>
            </div>

            <div class="contai-panel-body">
                <div class="contai-notice contai-notice-warning">
                    <div class="contai-notice-icon">
                        <span class="dashicons dashicons-info"></span>
                    </div>
                    <div class="contai-notice-body">
                        <p><?php esc_html_e('Make sure you have completed the setup wizard and configured your site topic before generating comments.', '1platform-content-ai'); ?></p>
                    </div>
                </div>

                <form method="post" class="contai-comments-form">
                    <?php wp_nonce_field('contai_comments_nonce', 'contai_comments_nonce'); ?>

                    <div class="contai-form-grid contai-grid-2">
                        <div class="contai-form-group">
                            <label for="contai_num_posts" class="contai-label">
                                <?php esc_html_e('Number of Posts', '1platform-content-ai'); ?>
                            </label>
                            <input type="number" id="contai_num_posts" name="contai_num_posts"
                                   value="10" min="1" max="100" class="contai-input" required>
                            <p class="contai-help-text">
                                <?php esc_html_e('How many random posts to generate comments for (1-100)', '1platform-content-ai'); ?>
                            </p>
                        </div>

                        <div class="contai-form-group">
                            <label for="contai_comments_per_post" class="contai-label">
                                <?php esc_html_e('Comments per Post', '1platform-content-ai'); ?>
                            </label>
                            <input type="number" id="contai_comments_per_post" name="contai_comments_per_post"
                                   value="1" min="1" max="10" class="contai-input" required>
                            <p class="contai-help-text">
                                <?php esc_html_e('Number of comments to add to each post (1-10)', '1platform-content-ai'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="contai-button-group" style="padding: 20px; border-top: 1px solid #e5e5e5; background: #f8f9fa; margin: 0;">
                        <button type="submit" name="contai_generate_now" class="button button-primary" <?php echo ! $this->creditCheck['has_credits'] ? 'disabled' : ''; ?>>
                            <span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
                            <?php esc_html_e('Generate Comments Now', '1platform-content-ai'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    private function render_results_table(): void {
        ?>
        <div class="contai-panel">
            <div class="contai-panel-head">
                <div class="contai-panel-head-main">
                    <h2 class="contai-panel-title">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e('Generated Comments', '1platform-content-ai'); ?>
                    </h2>
                    <p class="contai-panel-desc">
                        <?php
                        printf(
                            /* translators: %d: number of comments created */
                            esc_html__('Successfully created %d comments across your posts', '1platform-content-ai'),
                            count($this->generated_comments)
                        ); ?>
                    </p>
                </div>
            </div>

            <div class="contai-panel-body">
                <div class="contai-comments-table">
                    <div class="contai-table-header">
                        <div class="contai-table-column contai-col-post"><?php esc_html_e('Post', '1platform-content-ai'); ?></div>
                        <div class="contai-table-column contai-col-author"><?php esc_html_e('Author', '1platform-content-ai'); ?></div>
                        <div class="contai-table-column contai-col-email"><?php esc_html_e('Email', '1platform-content-ai'); ?></div>
                        <div class="contai-table-column contai-col-comment"><?php esc_html_e('Comment', '1platform-content-ai'); ?></div>
                    </div>

                    <?php foreach ($this->generated_comments as $row): ?>
                        <div class="contai-table-row">
                            <div class="contai-table-column contai-col-post">
                                <a href="<?php echo esc_url($row['url']); ?>" target="_blank" class="contai-post-link">
                                    <span class="dashicons dashicons-media-document"></span>
                                    <?php echo esc_html($row['title']); ?>
                                </a>
                            </div>
                            <div class="contai-table-column contai-col-author">
                                <span class="contai-author-name"><?php echo esc_html($row['name']); ?></span>
                            </div>
                            <div class="contai-table-column contai-col-email">
                                <code class="contai-email-code"><?php echo esc_html($row['email']); ?></code>
                            </div>
                            <div class="contai-table-column contai-col-comment">
                                <p class="contai-comment-text"><?php echo esc_html($row['comment']); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }
}
