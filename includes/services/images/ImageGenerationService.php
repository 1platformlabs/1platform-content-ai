<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../api/OnePlatformClient.php';
require_once __DIR__ . '/../api/OnePlatformEndpoints.php';

/**
 * Generates images via the 1Platform API.
 *
 * API endpoint: POST /users/generations/images
 *
 * curl example (import into Postman):
 *
 *   curl -X POST "https://api.1platform.pro/api/v1/users/generations/images" \
 *     -H "Content-Type: application/json" \
 *     -H "Authorization: Bearer <APP_ACCESS_TOKEN>" \
 *     -H "x-user-token: <USER_ACCESS_TOKEN>" \
 *     -d '{
 *       "count": 1,
 *       "context": "Minimalist and professional logo for the website example.com..."
 *     }'
 *
 * Response:
 *
 *   {
 *     "success": true,
 *     "data": {
 *       "images": [
 *         { "url": "https://cdn.1platform.pro/generated/abc123.png" }
 *       ]
 *     }
 *   }
 */
class ContaiImageGenerationService {

    private ContaiOnePlatformClient $client;

    public function __construct(?ContaiOnePlatformClient $client = null) {
        $this->client = $client ?? ContaiOnePlatformClient::create();
    }

    public static function create(): self {
        return new self();
    }

    /**
     * Request image generation from the API.
     *
     * @return array{success: bool, image_url: string, error: string|null}
     */
    public function generateImage(string $context): array {
        $response = $this->client->post(ContaiOnePlatformEndpoints::GENERATIONS_IMAGES, [
            'count'   => 1,
            'context' => $context,
        ]);

        if (!$response->isSuccess()) {
            return $this->error_result(
                $response->getMessage() ?? 'Image generation API request failed',
                $response->getStatusCode()
            );
        }

        $data = $response->getData();
        $images = $data['images'] ?? [];

        if (!is_array($images) || empty($images)) {
            return $this->error_result('Invalid response: missing images array');
        }

        $image_url = $images[0]['url'] ?? '';

        if (empty($image_url)) {
            return $this->error_result('Invalid response: image URL is empty');
        }

        return [
            'success'   => true,
            'image_url' => $image_url,
            'error'     => null,
        ];
    }

    /**
     * Generate an image and sideload it into the WordPress Media Library.
     *
     * @param string $context  The prompt/context for image generation.
     * @param string $filename Desired filename (without path).
     * @return int|WP_Error Attachment ID on success, WP_Error on failure.
     */
    public function generateAndSideload(string $context, string $filename): int|\WP_Error {
        $result = $this->generateImage($context);

        if (!$result['success']) {
            return new \WP_Error('api_error', $result['error']);
        }

        return $this->sideloadImage($result['image_url'], $filename);
    }

    /**
     * Download a remote image and add it to the Media Library.
     *
     * @return int|WP_Error Attachment ID on success, WP_Error on failure.
     */
    private function sideloadImage(string $image_url, string $filename): int|\WP_Error {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url(esc_url_raw($image_url));

        if (is_wp_error($tmp)) {
            return $tmp;
        }

        $file_array = [
            'name'     => sanitize_file_name($filename),
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload($file_array, 0);

        if (is_wp_error($attachment_id)) {
            wp_delete_file($tmp);
            return $attachment_id;
        }

        return $attachment_id;
    }

    private function error_result(string $message, int $status_code = 0): array {
        return [
            'success'   => false,
            'image_url' => '',
            'error'     => $message,
        ];
    }
}
