<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../api/OnePlatformClient.php';
require_once __DIR__ . '/../api/OnePlatformEndpoints.php';

/**
 * Generates comments for posts via the 1Platform API.
 *
 * API endpoint: POST /users/generations/comments
 *
 * curl example (import into Postman):
 *
 *   curl -X POST "https://api.1platform.pro/api/v1/users/generations/comments" \
 *     -H "Content-Type: application/json" \
 *     -H "Authorization: Bearer <APP_ACCESS_TOKEN>" \
 *     -H "x-user-token: <USER_ACCESS_TOKEN>" \
 *     -d '{
 *       "count": 3,
 *       "lang": "en",
 *       "context": "Eco products Best Reusable Water Bottles for 2025"
 *     }'
 *
 * Response:
 *
 *   {
 *     "success": true,
 *     "data": {
 *       "comments": [
 *         { "full_name": "Maria Lopez", "content": "Great article! I've been looking for..." },
 *         { "full_name": "Jake Turner", "content": "Thanks for the detailed comparison..." }
 *       ]
 *     }
 *   }
 */
class ContaiCommentsService {

    private ContaiOnePlatformClient $client;

    public function __construct(?ContaiOnePlatformClient $client = null) {
        $this->client = $client ?? ContaiOnePlatformClient::create();
    }

    public static function create(): self {
        return new self();
    }

    /**
     * Request N comments for a given post context.
     *
     * @return array{success: bool, comments: array, error: string|null}
     */
    public function generateComments(int $count, string $lang, string $context): array {
        $response = $this->client->post(ContaiOnePlatformEndpoints::GENERATIONS_COMMENTS, [
            'count'   => $count,
            'lang'    => $lang,
            'context' => $context,
        ]);

        if (!$response->isSuccess()) {
            return $this->error_result(
                $response->getMessage() ?? 'API request failed',
                $response->getStatusCode()
            );
        }

        $data = $response->getData();
        $comments = $data['comments'] ?? [];

        if (!is_array($comments)) {
            return $this->error_result('Invalid response structure: missing comments array');
        }

        return [
            'success'  => true,
            'comments' => $comments,
            'error'    => null,
        ];
    }

    /**
     * Build the context string from website topic and post title.
     */
    public static function buildContext(string $website_topic, string $post_title): string {
        return trim($website_topic) . ' ' . trim($post_title);
    }

    /**
     * Normalize a WordPress locale (e.g. "en_US") to a 2-letter language code.
     */
    public static function normalizeLang(string $locale): string {
        $code = strtolower(substr($locale, 0, 2));
        return preg_match('/^[a-z]{2}$/', $code) ? $code : 'en';
    }

    /**
     * Get the site language as a 2-letter code.
     * Tries the plugin's contai_site_language option first, then falls back to get_locale().
     */
    public static function getSiteLang(): string {
        $language = get_option('contai_site_language', '');

        if (!empty($language)) {
            $language_map = [
                'english' => 'en',
                'spanish' => 'es',
                'french'  => 'fr',
                'german'  => 'de',
                'italian' => 'it',
                'portuguese' => 'pt',
            ];

            $normalized = strtolower(trim($language));

            if (isset($language_map[$normalized])) {
                return $language_map[$normalized];
            }

            if (preg_match('/^[a-z]{2}$/', $normalized)) {
                return $normalized;
            }
        }

        return self::normalizeLang(get_locale());
    }

    private function error_result(string $message, int $status_code = 0): array {
        return [
            'success'  => false,
            'comments' => [],
            'error'    => $message,
        ];
    }
}
