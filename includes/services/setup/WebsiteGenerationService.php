<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../helpers/site-generation.php';
require_once __DIR__ . '/../legal/LegalPagesGenerator.php';

class ContaiWebsiteGenerationService
{
    public function generateCompleteWebsite(): array
    {
        $results = [
            'success' => true,
            'steps' => [],
            'errors' => []
        ];

        try {
            $theme = get_option('contai_wordpress_theme', 'blogfull');

            contai_install_theme($theme);
            $results['steps'][] = 'Theme installed';

            contai_setup_site_config();
            $results['steps'][] = 'Site config setup';

            contai_configure_site_metadata();
            $results['steps'][] = 'Site metadata configured';

            contai_handle_generate_widget_submit();
            $results['steps'][] = 'Widgets generated';

            try {
                contai_handle_generate_icon_submit();
                $results['steps'][] = 'Site icon generated';
            } catch (Exception $e) {
                $results['errors'][] = 'Icon generation failed (optional): ' . $e->getMessage();
            }

            $generator = new ContaiLegalPagesGenerator();
            $legalResult = $generator->generate();
            if ($legalResult['created'] > 0) {
                $results['steps'][] = sprintf('Legal pages created (%d)', $legalResult['created']);
            }
            if (!empty($legalResult['errors'])) {
                $results['errors'] = array_merge($results['errors'], $legalResult['errors']);
            }

            contai_generate_cookies_banner();
            $results['steps'][] = 'Cookie banner configured';

        } catch (Exception $e) {
            $results['success'] = false;
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }
}
