<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../comments/CommentsService.php';

class ContaiCommentsGenerationService
{
    private ContaiCommentsService $commentsService;

    public function __construct(?ContaiCommentsService $commentsService = null)
    {
        $this->commentsService = $commentsService ?? ContaiCommentsService::create();
    }

    public function generateCommentsForRecentPosts(int $numPosts, int $commentsPerPost): array
    {
        $numPosts = max(1, min(100, $numPosts));
        $commentsPerPost = max(1, min(10, $commentsPerPost));

        $posts = get_posts([
            'numberposts' => $numPosts,
            'orderby'     => 'date',
            'order'       => 'DESC',
            'post_type'   => 'post',
            'post_status' => 'publish',
        ]);

        if (empty($posts)) {
            throw new Exception('No published posts found to generate comments for');
        }

        $lang = ContaiCommentsService::getSiteLang();
        $websiteTopic = get_option('contai_site_theme', '');
        $generatedComments = [];

        foreach ($posts as $post) {
            $context = ContaiCommentsService::buildContext($websiteTopic, $post->post_title);
            $result = $this->commentsService->generateComments($commentsPerPost, $lang, $context);

            if (!$result['success']) {
                contai_log(sprintf(
                    '[WPContentAI] ContaiCommentsGenerationService failed for post #%d: %s',
                    $post->ID,
                    $result['error']
                ));
                continue;
            }

            foreach ($result['comments'] as $apiComment) {
                $fullName = sanitize_text_field($apiComment['full_name'] ?? '');
                $content = sanitize_textarea_field($apiComment['content'] ?? '');

                if (empty($fullName) || empty($content)) {
                    continue;
                }

                $commentDate = $this->generateRandomPastDate();
                $email = $this->generateEmailFromName($fullName);

                $commentId = wp_insert_comment([
                    'comment_post_ID'      => $post->ID,
                    'comment_author'       => $fullName,
                    'comment_author_email' => $email,
                    'comment_author_url'   => '',
                    'comment_content'      => $content,
                    'comment_approved'     => 1,
                    'comment_date'         => $commentDate,
                    'comment_date_gmt'     => get_gmt_from_date($commentDate),
                    'user_id'              => 0,
                ]);

                $generatedComments[] = [
                    'post_id'    => $post->ID,
                    'comment_id' => $commentId,
                    'author'     => $fullName,
                    'email'      => $email,
                ];
            }
        }

        return [
            'success'         => true,
            'generated_count' => count($generatedComments),
            'comments'        => $generatedComments,
        ];
    }

    private function generateRandomPastDate(): string
    {
        $timestamp = wp_rand(strtotime('-1 year'), time());
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function generateEmailFromName(string $name): string
    {
        $normalized = strtolower(str_replace(' ', '.', $name));
        $normalized = preg_replace('/[^a-z0-9.]/', '', $normalized);
        return $normalized . '@example.com';
    }
}
