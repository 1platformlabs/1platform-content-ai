<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../providers/WebsiteProvider.php';
require_once __DIR__ . '/../search-console/SearchConsoleService.php';

class ContaiSearchConsoleSetupService
{
    private ContaiWebsiteProvider $websiteProvider;
    private ContaiSearchConsoleService $searchConsoleService;

    public function __construct(
        ?ContaiWebsiteProvider $websiteProvider = null,
        ?ContaiSearchConsoleService $searchConsoleService = null
    ) {
        $this->websiteProvider      = $websiteProvider ?? new ContaiWebsiteProvider();
        $this->searchConsoleService = $searchConsoleService ?? new ContaiSearchConsoleService(null, $this->websiteProvider);
    }

    public function activateSearchConsole(): array
    {
        $results = [
            'success' => true,
            'steps' => [],
            'errors' => []
        ];

        try {
            $addResponse = $this->searchConsoleService->addToSearchConsole();
            if (!$addResponse->isSuccess()) {
                throw new Exception('Failed to add website to Search Console: ' . $addResponse->getMessage());
            }
            $this->websiteProvider->saveSearchConsoleConfig($addResponse->getData());
            $results['steps'][] = 'Website added to Search Console';

            $verificationFileResult = $this->searchConsoleService->createVerificationFile();
            if (!$verificationFileResult['success']) {
                throw new Exception('Failed to create verification file: ' . $verificationFileResult['message']);
            }
            $results['steps'][] = 'Verification file created';

            $verifyResponse = $this->searchConsoleService->verifyWebsite();
            if (!$verifyResponse->isSuccess()) {
                throw new Exception('Failed to verify website: ' . $verifyResponse->getMessage());
            }
            $this->websiteProvider->saveSearchConsoleConfig($verifyResponse->getData());
            $results['steps'][] = 'Website verified';

            $sitemaps = $this->websiteProvider->getSitemapUrls();
            if (!empty($sitemaps)) {
                $sitemapResponse = $this->searchConsoleService->submitSitemaps($sitemaps);
                if (!$sitemapResponse->isSuccess()) {
                    throw new Exception('Failed to submit sitemaps: ' . $sitemapResponse->getMessage());
                }
                $this->websiteProvider->saveSitemapsConfig($sitemapResponse->getData());
                $results['steps'][] = 'Sitemaps submitted';
            }

        } catch (Exception $e) {
            $results['success'] = false;
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }
}
