<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../../services/internal-links/InternalLinksQueueManager.php';

class ContaiInternalLinksQueueHandler {

    private const NONCE_ACTION = 'contai_internal_links_nonce';
    private const NONCE_FIELD = 'nonce';

    private const ACTION_ENQUEUE = 'enqueue_internal_links';
    private const ACTION_CLEAR = 'clear_internal_links_queue';

    private $queueManager;

    public function __construct() {
        $this->queueManager = new ContaiInternalLinksQueueManager();
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
        if (isset($_POST['contai_enqueue_internal_links'])) {
            return self::ACTION_ENQUEUE;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handleRequest() via verifyNonce().
        if (isset($_POST['contai_clear_internal_links_queue'])) {
            return self::ACTION_CLEAR;
        }

        return null;
    }

    private function executeCommand(string $action): void {
        try {
            switch ($action) {
                case self::ACTION_ENQUEUE:
                    $result = $this->enqueueInternalLinks();
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

    private function enqueueInternalLinks(): array {
        $validation = $this->validateEnqueueRequest();

        if (!$validation['valid']) {
            return [
                'success' => false,
                'message' => $validation['message']
            ];
        }

        $limit = $validation['limit'];
        $enqueuedCount = $this->queueManager->enqueueAllPublishedPosts($limit);

        return [
            'success' => true,
            'message' => sprintf(
                /* translators: %d: number of posts enqueued for internal link processing */
                _n(
                    'Successfully enqueued %d post for internal link processing.',
                    'Successfully enqueued %d posts for internal link processing.',
                    $enqueuedCount,
                    '1platform-content-ai'
                ),
                $enqueuedCount
            )
        ];
    }

    private function validateEnqueueRequest(): array {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handleRequest() via verifyNonce().
        $limit = isset($_POST['limit']) ? absint(wp_unslash($_POST['limit'])) : 0;

        if ($limit < 1 || $limit > 100) {
            return [
                'valid' => false,
                'message' => __('Number of posts must be between 1 and 100.', '1platform-content-ai')
            ];
        }

        return [
            'valid' => true,
            'limit' => $limit
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
                    'page' => 'contai-apps',
                    'section' => 'internal-links'
                ],
                $params
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($url);
        exit;
    }
}
