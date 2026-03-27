<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../publisuites/PublisuitesService.php';

class ContaiPublisuitesSetupService
{
    private ContaiPublisuitesService $publisuiteService;

    public function __construct(
        ?ContaiPublisuitesService $publisuiteService = null
    ) {
        $this->publisuiteService = $publisuiteService ?? new ContaiPublisuitesService();
    }

    public function activatePublisuites(): array
    {
        $results = [
            'success' => true,
            'steps' => [],
            'errors' => []
        ];

        try {
            // Step 1: Register website in marketplace (API call action=add)
            $connectResponse = $this->publisuiteService->connectWebsite();
            if (!$connectResponse->isSuccess()) {
                throw new Exception('Failed to register website: ' . $connectResponse->getMessage());
            }

            $data = $connectResponse->getData();
            $this->publisuiteService->savePublisuitesConfig([
                'publisuites_id' => $data['publisuites_id'] ?? '',
                'verification_file_name' => $data['verification_file_name'] ?? '',
                'verification_file_content' => $data['verification_file_content'] ?? '',
                'status' => 'pending_verification',
                'verified' => false,
            ]);
            $results['steps'][] = 'Website registered in marketplace';

            // Step 2: Create verification file in WordPress root
            $fileResult = $this->publisuiteService->createVerificationFile();
            if (!$fileResult['success']) {
                throw new Exception('Failed to create verification file: ' . $fileResult['message']);
            }
            $results['steps'][] = 'Verification file created';

            // Step 3: Verify website ownership (API call action=verify)
            $verifyResponse = $this->publisuiteService->verifyWebsite();
            if (!$verifyResponse->isSuccess()) {
                throw new Exception('Failed to verify website: ' . $verifyResponse->getMessage());
            }

            $verifyData = $verifyResponse->getData();
            $config = $this->publisuiteService->getPublisuitesConfig();
            if ($config) {
                $config['verified'] = $verifyData['verified'] ?? false;
                $config['verifiedAt'] = $verifyData['verified_at'] ?? null;
                $config['status'] = ($verifyData['verified'] ?? false) ? 'active' : 'pending_verification';
                $this->publisuiteService->savePublisuitesConfig($config);
            }
            $results['steps'][] = 'Website verified';

        } catch (Exception $e) {
            $results['success'] = false;
            $results['errors'][] = $e->getMessage();
            return $results;
        }

        return $results;
    }
}
