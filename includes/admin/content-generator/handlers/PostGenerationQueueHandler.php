<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../../services/jobs/QueueManager.php';

class ContaiPostGenerationQueueHandler {

    private const NONCE_ACTION = 'contai_post_generator_nonce';
    private const NONCE_FIELD = 'contai_post_generator_nonce';

    private const ACTION_ENQUEUE = 'enqueue_posts';
    private const ACTION_CLEAR = 'clear_queue';

    private $queueManager;

    public function __construct(?ContaiQueueManager $queueManager = null) {
        $this->queueManager = $queueManager ?? new ContaiQueueManager();
    }

    public function handleRequest(): void {
        if (!$this->isPostRequest()) {
            return;
        }

        if (!$this->verifyNonce()) {
            $this->redirectWithError(__('Security verification failed. Please try again.', '1platform-content-ai'));
            return;
        }

        if (!$this->verifyCapability()) {
            $this->redirectWithError(__('You do not have permission to perform this action.', '1platform-content-ai'));
            return;
        }

        $action = $this->getRequestedAction();

        if (!$action) {
            return;
        }

        $this->executeCommand($action);
    }

    private function isPostRequest(): bool {
        return isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
    }

    private function verifyNonce(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        return isset($_POST[self::NONCE_FIELD]) &&
               wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD])), self::NONCE_ACTION);
    }

    private function verifyCapability(): bool {
        return current_user_can('manage_options');
    }

    private function getRequestedAction(): ?string {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handleRequest() via verifyNonce().
        if (isset($_POST['contai_enqueue_posts'])) {
            return self::ACTION_ENQUEUE;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handleRequest() via verifyNonce().
        if (isset($_POST['contai_clear_queue'])) {
            return self::ACTION_CLEAR;
        }

        return null;
    }

    private function executeCommand(string $action): void {
        try {
            switch ($action) {
                case self::ACTION_ENQUEUE:
                    $result = $this->enqueuePosts();
                    break;

                case self::ACTION_CLEAR:
                    $result = $this->clearQueue();
                    break;

                default:
                    $this->redirectWithError(__('Invalid action.', '1platform-content-ai'));
                    return;
            }

            if ($result['success']) {
                $this->redirectWithSuccess($result['message']);
            } else {
                $this->redirectWithError($result['message']);
            }
        } catch (Exception $e) {
            $this->redirectWithError($e->getMessage());
        }
    }

    private function enqueuePosts(): array {
        // Validate credits before enqueueing posts
        require_once __DIR__ . '/../../../services/billing/CreditGuard.php';
        $creditGuard = new ContaiCreditGuard();
        $creditCheck = $creditGuard->validateCredits();

        if (!$creditCheck['has_credits']) {
            return [
                'success' => false,
                'message' => $creditCheck['message']
            ];
        }

        $validation = $this->validateEnqueueRequest();

        if (!$validation['valid']) {
            return [
                'success' => false,
                'message' => $validation['message']
            ];
        }

        $count = $validation['count'];
        $config = [
            'lang' => $validation['lang'],
            'country' => $validation['country'],
            'image_provider' => $validation['image_provider'],
        ];

        $enqueuedCount = $this->queueManager->enqueuePostGeneration($count, $config);

        return [
            'success' => true,
            'message' => sprintf(
                /* translators: %d: number of posts enqueued for generation */
                _n(
                    'Successfully enqueued %d post for generation.',
                    'Successfully enqueued %d posts for generation.',
                    $enqueuedCount,
                    '1platform-content-ai'
                ),
                $enqueuedCount
            )
        ];
    }

    private function validateEnqueueRequest(): array {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in handleRequest() via verifyNonce().
        $count = isset($_POST['post_count']) ? absint(wp_unslash($_POST['post_count'])) : 0;
        $lang = sanitize_text_field(wp_unslash($_POST['content_lang'] ?? ''));
        $country = sanitize_text_field(wp_unslash($_POST['content_country'] ?? ''));
        $imageProvider = sanitize_text_field(wp_unslash($_POST['image_provider'] ?? ''));
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ($count < 1 || $count > 100) {
            return [
                'valid' => false,
                'message' => __('Number of posts must be between 1 and 100.', '1platform-content-ai')
            ];
        }

        if (empty($lang) || empty($country)) {
            return [
                'valid' => false,
                'message' => __('Language and country are required.', '1platform-content-ai')
            ];
        }

        $validProviders = ['pexels', 'pixabay'];
        if (!in_array($imageProvider, $validProviders, true)) {
            return [
                'valid' => false,
                'message' => __('Invalid image provider selected.', '1platform-content-ai')
            ];
        }

        return [
            'valid' => true,
            'count' => $count,
            'lang' => $lang,
            'country' => $country,
            'image_provider' => $imageProvider
        ];
    }

    private function clearQueue(): array {
        $deletedCount = $this->queueManager->clearAllJobs();

        return [
            'success' => true,
            'message' => sprintf(
                /* translators: %d: number of jobs cleared from the queue */
                _n(
                    'Successfully cleared %d job from the queue.',
                    'Successfully cleared %d jobs from the queue.',
                    $deletedCount,
                    '1platform-content-ai'
                ),
                $deletedCount
            )
        ];
    }

    private function redirectWithSuccess(string $message): void {
        $this->redirect([
            'success' => 1,
            'message' => $message
        ]);
    }

    private function redirectWithError(string $message): void {
        $this->redirect([
            'error' => 1,
            'message' => $message
        ]);
    }

    private function redirect(array $params): void {
        $url = add_query_arg(
            array_merge(
                [
                    'page' => 'contai-content-generator',
                    'section' => 'post-generator'
                ],
                $params
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($url);
        exit;
    }
}
