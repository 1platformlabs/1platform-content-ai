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
     * Pick the two date columns for a generated comment so the comment cannot
     * be stamped before the post it belongs to.
     *
     * Both generation paths used to draw from a fixed "somewhere in the last 12
     * months" window with no reference to the post at all, while posts
     * themselves get spread over the last 365 days (see
     * ContaiPostMaintenancePanel::randomize_post_dates). With both dates drawn
     * independently from the same year, roughly half of every site's comments
     * landed *before* their own article — a reader (or an ad-network policy
     * reviewer) sees a January article answered the previous March. That is the
     * "appears inactive or lacks sufficient curation" signal reported in
     * issues 118 and 119, and it is the one part of those reports that is a
     * plain defect rather than a content decision.
     *
     * Arithmetic runs in GMT because post_date is a *local* column: passing it
     * to strtotime() would have PHP read it in its own timezone (UTC under
     * WordPress) and shift the anchor. The local column is then derived from
     * the unambiguous one via get_date_from_gmt(), the same direction
     * ContaiAgentActionHandler uses for post_date_gmt -> post_date.
     *
     * @param object $post Post object (WP_Post) whose date bounds the draw.
     * @return array{0: string, 1: string} [local 'Y-m-d H:i:s', GMT 'Y-m-d H:i:s']
     */
    public static function commentDatesForPost(object $post): array {
        $post_timestamp = self::postTimestampGmt($post);
        $now = time();

        // wp_rand(n, n) returns n, so a post dated in the future — scheduled,
        // or a clock skew between PHP and MySQL — collapses to the post's own
        // instant instead of inverting the window.
        $timestamp = wp_rand($post_timestamp, max($post_timestamp, $now));

        $gmt = gmdate('Y-m-d H:i:s', $timestamp);

        return [get_date_from_gmt($gmt), $gmt];
    }

    /**
     * Resolve a post's publication instant as a GMT unix timestamp.
     *
     * post_date_gmt is preferred because it needs no timezone conversion, but
     * it is not always populated (a zero date survives some import paths), so
     * the local column is the fallback. If neither reads, "now" is the only
     * safe anchor left: a comment stamped now cannot precede a post that
     * already exists.
     *
     * The local column is only converted if the GMT one did not read, because
     * that conversion is the lossy direction and there is no reason to pay for
     * it on a healthy post.
     */
    private static function postTimestampGmt(object $post): int {
        $gmt = isset($post->post_date_gmt) ? trim((string) $post->post_date_gmt) : '';
        $timestamp = self::gmtStringToTimestamp($gmt);

        if ($timestamp !== null) {
            return $timestamp;
        }

        $local = isset($post->post_date) ? trim((string) $post->post_date) : '';

        if ($local !== '') {
            $timestamp = self::gmtStringToTimestamp(get_gmt_from_date($local));

            if ($timestamp !== null) {
                return $timestamp;
            }
        }

        return time();
    }

    /**
     * Parse a GMT 'Y-m-d H:i:s' column, or null when it does not describe a
     * real instant.
     *
     * Both guards are load-bearing, and neither is the obvious one:
     *
     * - An empty string must be rejected before strtotime() sees it, because
     *   strtotime(' +0000') returns *now*. Letting that through would anchor a
     *   post on the current time and silently discard the date it really has.
     * - The result has to be strictly positive rather than merely not-false.
     *   A zero date does not fail to parse — strtotime('0000-00-00 00:00:00
     *   +0000') is -62169984000 — and get_gmt_from_date() reports a date it
     *   cannot parse by returning gmdate($format, 0), the epoch, rather than
     *   false (wp-includes/formatting.php:3673-3675, WordPress 7.0.2), which
     *   strtotime() reads back as a perfectly valid 0. Without this, an
     *   unreadable post date would anchor its comments in 1970 — the same class
     *   of visible nonsense this whole method exists to remove. It also covers
     *   the year-0 forms without a second string-shape check.
     */
    private static function gmtStringToTimestamp(string $gmt): ?int {
        if ($gmt === '') {
            return null;
        }

        $timestamp = strtotime($gmt . ' +0000');

        return ($timestamp !== false && $timestamp > 0) ? $timestamp : null;
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
