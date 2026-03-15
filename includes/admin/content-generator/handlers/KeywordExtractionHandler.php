<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../../database/repositories/JobRepository.php';
require_once __DIR__ . '/../../../database/models/Job.php';
require_once __DIR__ . '/../../../services/jobs/KeywordExtractionJob.php';

class ContaiKeywordExtractionHandler {

    private const NONCE_ACTION = 'contai_keyword_extractor_nonce';
    private const NONCE_FIELD = 'contai_keyword_extractor_nonce';

    private const ACTION_EXTRACT = 'extract_keywords';

    private $jobRepository;

    public function __construct() {
        $this->jobRepository = new ContaiJobRepository();
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
        if (isset($_POST['contai_extract_keywords'])) {
            return self::ACTION_EXTRACT;
        }

        return null;
    }

    private function executeCommand(string $action): void {
        try {
            switch ($action) {
                case self::ACTION_EXTRACT:
                    $result = $this->extractKeywords();
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

    private function extractKeywords(): array {
        $validation = $this->validateExtractionRequest();

        if (!$validation['valid']) {
            return [
                'success' => false,
                'message' => $validation['message']
            ];
        }

        $payload = [
            'domain' => $validation['domain'],
            'country' => $validation['country'],
            'lang' => $validation['lang']
        ];

        $job = ContaiJob::create(
            ContaiKeywordExtractionJob::TYPE,
            $payload,
            0
        );

        $created = $this->jobRepository->create($job);

        if (!$created) {
            return [
                'success' => false,
                'message' => __('Failed to enqueue keyword extraction job.', '1platform-content-ai')
            ];
        }

        return [
            'success' => true,
            'message' => sprintf(
                /* translators: %s: domain name for keyword extraction */
                __('Keyword extraction job has been queued. Domain: %s. You can check the Keywords List page for results.', '1platform-content-ai'),
                $validation['domain']
            )
        ];
    }

    private function validateExtractionRequest(): array {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in handleRequest() via verifyNonce().
        $domain = esc_url_raw(wp_unslash($_POST['contai_source_url'] ?? ''));
        $country = sanitize_text_field(wp_unslash($_POST['contai_country'] ?? ''));
        $lang = sanitize_text_field(wp_unslash($_POST['contai_target_language'] ?? ''));
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if (empty($domain) || empty($country) || empty($lang)) {
            return [
                'valid' => false,
                'message' => __('Please fill in all required fields.', '1platform-content-ai')
            ];
        }

        if (!filter_var($domain, FILTER_VALIDATE_URL)) {
            return [
                'valid' => false,
                'message' => __('Please enter a valid URL.', '1platform-content-ai')
            ];
        }

        $validLanguages = ['en', 'es'];
        if (!in_array($lang, $validLanguages, true)) {
            return [
                'valid' => false,
                'message' => __('Invalid language selected.', '1platform-content-ai')
            ];
        }

        $validCountries = ['us', 'es'];
        if (!in_array($country, $validCountries, true)) {
            return [
                'valid' => false,
                'message' => __('Invalid country selected.', '1platform-content-ai')
            ];
        }

        return [
            'valid' => true,
            'domain' => $domain,
            'country' => $country,
            'lang' => $lang
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
                    'section' => 'keyword-extractor'
                ],
                $params
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($url);
        exit;
    }
}
