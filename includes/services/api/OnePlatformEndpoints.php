<?php
/**
 * Centralized API endpoint definitions for the 1Platform backend.
 *
 * All endpoint paths consumed by this plugin are defined here.
 * Base URL (e.g. https://api.1platform.pro/api/v1) is configured in Config.php.
 * These paths are appended to the base URL by ContaiOnePlatformClient.
 *
 * OpenAPI source: 1Platform API v1
 *
 * cURL examples (import to Postman):
 *
 * # App token
 * curl -X POST https://api-qa.1platform.pro/api/v1/auth/token \
 *   -H "Content-Type: application/json" \
 *   -d '{"apiKey": "<APP_API_KEY>"}'
 *
 * # User token
 * curl -X POST https://api-qa.1platform.pro/api/v1/users/token \
 *   -H "Content-Type: application/json" \
 *   -H "Authorization: Bearer <APP_ACCESS_TOKEN>" \
 *   -d '{"apiKey": "<USER_API_KEY>"}'
 *
 * # Create async content generation job
 * curl -X POST https://api-qa.1platform.pro/api/v1/posts/content/ \
 *   -H "Content-Type: application/json" \
 *   -H "Authorization: Bearer <APP_ACCESS_TOKEN>" \
 *   -H "x-user-token: <USER_ACCESS_TOKEN>" \
 *   -d '{"keyword": "best running shoes", "lang": "en", "country": "us", "image_provider": "pexels", "categories": ["tech","gadgets"]}'
 *
 * # Poll content generation job status
 * curl -X GET https://api-qa.1platform.pro/api/v1/posts/content/jobs/<JOB_ID> \
 *   -H "Authorization: Bearer <APP_ACCESS_TOKEN>" \
 *   -H "x-user-token: <USER_ACCESS_TOKEN>"
 */

if (!defined('ABSPATH')) exit;

class ContaiOnePlatformEndpoints {

    // ── Authentication ──────────────────────────────────────────
    const AUTH_TOKEN = '/auth/token';
    const USERS_TOKEN = '/users/token';

    // ── Users ───────────────────────────────────────────────────
    const USERS_CREATE = '/users';
    const USERS_PROFILE = '/users/profile';
    const USERS_BILLING = '/users/billing';
    const USERS_CATEGORIES = '/users/categories';
    const USERS_WEBSITES = '/users/websites';
    const USERS_TRANSACTIONS = '/users/transactions';

    // ── Content Generation ──────────────────────────────────────
    const POSTS_KEYWORDS = '/posts/keywords/';
    const POSTS_CONTENT = '/posts/content/';
    const POSTS_CONTENT_JOBS = '/posts/content/jobs/';
    const POSTS_INDEXING = '/posts/indexing/';

    // ── AI Generations ──────────────────────────────────────────
    const GENERATIONS_COMMENTS = '/users/generations/comments';
    const GENERATIONS_IMAGES = '/users/generations/images';
    const GENERATIONS_PROFILE = '/users/generations/profile';

    // ── System ──────────────────────────────────────────────────
    const HEALTH = '/health/';

    // ── Webhooks ────────────────────────────────────────────────
    const WEBHOOKS_TRANSACTIONS = '/webhooks/transactions';

    // ── Businesses ──────────────────────────────────────────────
    const BUSINESSES = '/businesses';

    // ── Parameterized Paths ─────────────────────────────────────

    public static function postsContentJobById(string $job_id): string {
        return sprintf('/posts/content/jobs/%s', $job_id);
    }

    public static function websiteById(string $website_id): string {
        return sprintf('/users/websites/%s', $website_id);
    }

    public static function websiteSearchConsole(string $website_id): string {
        return sprintf('/users/websites/%s/searchconsole', $website_id);
    }

    public static function websitePublisuites(string $website_id): string {
        return sprintf('/users/websites/%s/publisuites', $website_id);
    }

    public static function websiteLegal(string $website_id): string {
        return sprintf('/users/websites/%s/legal', $website_id);
    }

    public static function subscriptionById(string $subscription_id): string {
        return sprintf('/users/subscriptions/%s', $subscription_id);
    }

    public static function businessById(string $business_id): string {
        return sprintf('/businesses/%s', $business_id);
    }

    public static function businessBranches(string $business_id): string {
        return sprintf('/businesses/%s/branches', $business_id);
    }

    public static function businessBranchById(string $business_id, string $branch_id): string {
        return sprintf('/businesses/%s/branches/%s', $business_id, $branch_id);
    }

    public static function businessInvoices(string $business_id): string {
        return sprintf('/businesses/%s/invoices', $business_id);
    }

    public static function businessInvoiceById(string $business_id, string $invoice_id): string {
        return sprintf('/businesses/%s/invoices/%s', $business_id, $invoice_id);
    }
}
