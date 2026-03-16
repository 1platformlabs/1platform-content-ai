<?php
if (!defined('ABSPATH')) exit;

class ContaiNoticeHelper {

    /**
     * Build a formatted error notice HTML string.
     *
     * @param string $action Human-readable action description
     * @param ContaiOnePlatformResponse $response The API response
     * @param string $fallbackMessage Fallback message if response has none
     * @param string|null $websiteId Optional website ID for context
     * @return string HTML notice string (already escaped)
     */
    public static function buildErrorNotice(
        string $action,
        ContaiOnePlatformResponse $response,
        string $fallbackMessage = '',
        ?string $websiteId = null
    ): string {
        $status = $response->getStatusCode();
        $message = $response->getMessage();
        $traceId = $response->getTraceId();

        // Build message with priority: backend msg > fallback > generic
        if (!empty($message)) {
            $notice = sprintf(
                '%s failed (HTTP %d): %s',
                $action,
                $status,
                $message
            );
        } elseif (!empty($fallbackMessage)) {
            $notice = sprintf(
                '%s failed (HTTP %d): %s',
                $action,
                $status,
                $fallbackMessage
            );
        } else {
            $notice = sprintf(
                '%s failed (HTTP %d)',
                $action,
                $status
            );
        }

        $html = esc_html($notice);

        // Add correlation ID link if available
        if (!empty($traceId)) {
            $logsUrl = admin_url('admin.php?page=contai-logs&trace_id=' . urlencode($traceId));
            if (!empty($websiteId)) {
                $logsUrl .= '&website_id=' . urlencode($websiteId);
            }
            $html .= ' <a href="' . esc_url($logsUrl) . '">[Ref: ' . esc_html($traceId) . ']</a>';
        }

        return $html;
    }
}
