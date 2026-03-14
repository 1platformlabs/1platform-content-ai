<?php

/**
 * AI Generation Helper Functions
 *
 * Functions for generating content and assets using AI services:
 * - WPContentAI API: Site icon generation (via ContaiImageGenerationService)
 * - OpenAI: Tagline generation
 * - Replicate: Image generation (Stable Diffusion)
 *
 * @package Content AI
 * @since 1.10.0
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/../services/images/ImageGenerationService.php';

/**
 * Generate and set site icon via 1Platform image generation API.
 *
 * Builds a context prompt from $host and $theme, requests the image from
 * the backend, sideloads it into the Media Library, and overwrites site_icon.
 *
 * curl example (import into Postman):
 *
 *   curl -X POST "https://api.1platform.pro/api/v1/users/123/generations/images" \
 *     -H "Content-Type: application/json" \
 *     -H "Authorization: Bearer <token>" \
 *     -d '{
 *       "count": 1,
 *       "context": "Minimalist and professional icon for the website example.com. ..."
 *     }'
 *
 * @return int|WP_Error Attachment ID on success, WP_Error on failure
 */
function contai_generate_and_set_site_icon_from_openai()
{
    $host = sanitize_text_field(wp_parse_url(home_url(), PHP_URL_HOST));
    $theme = sanitize_text_field(get_option('contai_site_theme', 'general'));

    $context = "Minimalist and professional logo for the website $host. "
        . "The logo must include the domain name '$host' in a modern, elegant font. "
        . "Add a simple plant-related icon such as a $theme next to the text. "
        . "The logo must have a transparent background, no white margins or extra spacing "
        . "— cropped tightly to the content. Typography and icon should look balanced and clean.";

    $service = ContaiImageGenerationService::create();
    $filename = "icon-$host.png";
    $attachment_id = $service->generateAndSideload($context, $filename);

    if (is_wp_error($attachment_id)) {
        return $attachment_id;
    }

    update_option('site_icon', $attachment_id);

    return $attachment_id;
}

