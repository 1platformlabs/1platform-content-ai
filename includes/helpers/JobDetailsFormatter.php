<?php

if (!defined('ABSPATH')) exit;

class ContaiJobDetailsFormatter
{
    private const JOB_TYPE_LABELS = [
        'post_generation' => 'Post Generation',
        'internal_link' => 'Internal Links',
        'keyword_extraction' => 'ContaiKeyword Extraction',
        'site_generation' => 'Site Generation',
    ];

    private const STATUS_COLORS = [
        'pending' => '#0073aa',
        'processing' => '#f0b849',
        'done' => '#46b450',
        'failed' => '#dc3232',
    ];

    private const STATUS_ICONS = [
        'pending' => '⏳',
        'processing' => '⚙️',
        'done' => '✓',
        'failed' => '✗',
    ];

    public static function formatJobType(string $jobType): string
    {
        return self::JOB_TYPE_LABELS[$jobType] ?? ucwords(str_replace('_', ' ', $jobType));
    }

    public static function formatStatus(string $status): string
    {
        $icon = self::STATUS_ICONS[$status] ?? '';
        $label = ucfirst($status);
        $color = self::STATUS_COLORS[$status] ?? '#000';

        return sprintf(
            '<span style="color: %s; font-weight: 600;">%s %s</span>',
            esc_attr($color),
            $icon,
            esc_html($label)
        );
    }

    public static function formatDuration(?int $seconds): string
    {
        if ($seconds === null || $seconds < 0) {
            return 'N/A';
        }

        if ($seconds < 60) {
            return sprintf('%ds', $seconds);
        }

        if ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $secs = $seconds % 60;
            return sprintf('%dm %ds', $minutes, $secs);
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return sprintf('%dh %dm', $hours, $minutes);
    }

    public static function formatPayloadSummary(string $payloadJson): string
    {
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return 'Invalid payload';
        }

        $parts = [];

        if (isset($payload['keyword_id'])) {
            $parts[] = sprintf('ContaiKeyword ID: %d', $payload['keyword_id']);
        }

        if (isset($payload['post_id'])) {
            $parts[] = sprintf('Post ID: %d', $payload['post_id']);
        }

        if (isset($payload['keyword'])) {
            $parts[] = sprintf('ContaiKeyword: "%s"', esc_html($payload['keyword']));
        }

        if (isset($payload['title'])) {
            $parts[] = sprintf('Title: "%s"', esc_html(self::truncate($payload['title'], 50)));
        }

        if (isset($payload['niche'])) {
            $parts[] = sprintf('Niche: %s', esc_html($payload['niche']));
        }

        if (isset($payload['posts_count'])) {
            $parts[] = sprintf('Posts: %d', $payload['posts_count']);
        }

        return !empty($parts) ? implode(' | ', $parts) : 'No details';
    }

    public static function formatPriority(int $priority): string
    {
        if ($priority >= 10) {
            return sprintf('<span style="color: #dc3232; font-weight: 600;">High (%d)</span>', $priority);
        }

        if ($priority >= 5) {
            return sprintf('<span style="color: #f0b849; font-weight: 600;">Medium (%d)</span>', $priority);
        }

        return sprintf('<span style="color: #72777c;">Normal (%d)</span>', $priority);
    }

    public static function formatAttempts(int $attempts, int $maxAttempts): string
    {
        $percentage = $maxAttempts > 0 ? ($attempts / $maxAttempts) * 100 : 0;

        if ($percentage >= 80) {
            $color = '#dc3232';
        } elseif ($percentage >= 50) {
            $color = '#f0b849';
        } else {
            $color = '#46b450';
        }

        return sprintf(
            '<span style="color: %s;">%d / %d</span>',
            $color,
            $attempts,
            $maxAttempts
        );
    }

    public static function formatDateTime(?string $datetime): string
    {
        if (empty($datetime)) {
            return 'N/A';
        }

        $timestamp = strtotime($datetime);
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return sprintf('%d seconds ago', $diff);
        }

        if ($diff < 3600) {
            return sprintf('%d minutes ago', floor($diff / 60));
        }

        if ($diff < 86400) {
            return sprintf('%d hours ago', floor($diff / 3600));
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    public static function formatRelativeTime(?string $datetime): string
    {
        if (empty($datetime)) {
            return 'N/A';
        }

        return human_time_diff(strtotime($datetime), current_time('timestamp')) . ' ago';
    }

    public static function getStatusBadgeClass(string $status): string
    {
        $classes = [
            'pending' => 'contai-badge-info',
            'processing' => 'contai-badge-warning',
            'done' => 'contai-badge-success',
            'failed' => 'contai-badge-danger',
        ];

        return $classes[$status] ?? 'contai-badge-default';
    }

    public static function isJobStuck(array $job, int $thresholdMinutes = 30): bool
    {
        if ($job['status'] !== 'processing' || empty($job['processed_at'])) {
            return false;
        }

        $processedTime = strtotime($job['processed_at']);
        $elapsedMinutes = (time() - $processedTime) / 60;

        return $elapsedMinutes > $thresholdMinutes;
    }

    private static function truncate(string $text, int $length = 50): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }

        return substr($text, 0, $length) . '...';
    }
}
