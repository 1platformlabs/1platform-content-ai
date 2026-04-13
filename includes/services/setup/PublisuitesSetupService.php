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

    /**
     * Auto-connect Publisuites using website data from the API.
     *
     * Reads actions.publisuites.verification from the website response and:
     * - If publisuites_id exists AND verified: saves config as active (no API calls)
     * - If publisuites_id exists AND NOT verified: creates verification file + verifies
     * - If no publisuites_id: runs the full activation flow (add → file → verify)
     *
     * @param array $websiteData Full website data from the API response
     * @return array{success: bool, action: string, message: string}
     */
    public function autoConnect(array $websiteData): array
    {
        $publisuites = $websiteData['actions']['publisuites']['verification'] ?? [];
        $publisuitesId = $publisuites['publisuites_id'] ?? null;

        if (empty($publisuitesId)) {
            $result = $this->activatePublisuites();

            return [
                'success' => $result['success'],
                'action'  => 'full_activation',
                'message' => $result['success']
                    ? 'Publisuites registered and verified'
                    : implode('; ', $result['errors']),
            ];
        }

        $verified = $publisuites['verified'] ?? false;

        if ($verified) {
            $this->publisuiteService->savePublisuitesConfig([
                'publisuites_id'            => $publisuitesId,
                'verification_file_name'    => $publisuites['file_name'] ?? '',
                'verification_file_content' => $publisuites['file_content'] ?? '',
                'verified'                  => true,
                'verified_at'               => $publisuites['verified_at'] ?? null,
                'status'                    => 'active',
                'marketplace_status'        => $publisuites['marketplace_status'] ?? null,
            ]);

            return [
                'success' => true,
                'action'  => 'restored',
                'message' => 'Publisuites connection restored from API',
            ];
        }

        // publisuites_id exists but not verified — create file and verify
        $this->publisuiteService->savePublisuitesConfig([
            'publisuites_id'            => $publisuitesId,
            'verification_file_name'    => $publisuites['file_name'] ?? '',
            'verification_file_content' => $publisuites['file_content'] ?? '',
            'verified'                  => false,
            'status'                    => 'pending_verification',
        ]);

        $fileResult = $this->publisuiteService->createVerificationFile();
        if (!$fileResult['success']) {
            return [
                'success' => false,
                'action'  => 'verification_file_failed',
                'message' => $fileResult['message'] ?? 'Failed to create verification file',
            ];
        }

        $verifyResponse = $this->publisuiteService->verifyWebsite();
        if (!$verifyResponse->isSuccess()) {
            return [
                'success' => false,
                'action'  => 'verification_failed',
                'message' => $verifyResponse->getMessage() ?? 'Failed to verify website',
            ];
        }

        $verifyData = $verifyResponse->getData();
        $config = $this->publisuiteService->getPublisuitesConfig();
        if ($config) {
            $config['verified'] = $verifyData['verified'] ?? false;
            $config['verifiedAt'] = $verifyData['verified_at'] ?? null;
            $config['status'] = ($verifyData['verified'] ?? false) ? 'active' : 'pending_verification';
            $this->publisuiteService->savePublisuitesConfig($config);
        }

        return [
            'success' => true,
            'action'  => 'verified',
            'message' => 'Publisuites verified and connected',
        ];
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
