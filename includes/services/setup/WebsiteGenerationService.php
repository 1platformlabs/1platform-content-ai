<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../helpers/site-generation.php';
require_once __DIR__ . '/../../providers/WebsiteProvider.php';
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
            $theme = get_option('contai_wordpress_theme', 'astra');

            contai_install_theme($theme);
            contai_apply_theme_defaults($theme);
            $results['steps'][] = 'Theme installed and configured';

            try {
                $website_provider = new ContaiWebsiteProvider();
                $website_provider->updateWebsite( array( 'theme' => sanitize_text_field( $theme ) ) );
                $results['steps'][] = 'Theme tracked in API';
            } catch ( Exception $e ) {
                $results['errors'][] = 'Theme tracking failed (non-critical): ' . $e->getMessage();
            }

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

            try {
                contai_create_footer_menu_with_legal_pages();
                $results['steps'][] = 'Footer menu with legal pages created';
            } catch (Exception $e) {
                $results['errors'][] = 'Footer menu creation failed (non-critical): ' . $e->getMessage();
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
