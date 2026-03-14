<?php

if (!defined('ABSPATH')) exit;

class ContaiTimestampHelper
{
    public static function isValidTimestamp(?string $timestamp): bool
    {
        if (empty($timestamp)) {
            return false;
        }

        $time = strtotime($timestamp);
        if ($time === false) {
            return false;
        }

        $now = current_time('timestamp');
        $fiveMinutesFromNow = $now + (5 * 60);

        return $time <= $fiveMinutesFromNow;
    }

    public static function isInFuture(string $timestamp): bool
    {
        $time = strtotime($timestamp);
        $now = current_time('timestamp');

        return $time > ($now + 60);
    }

    public static function getAgeInSeconds(string $timestamp): int
    {
        $time = strtotime($timestamp);
        $now = current_time('timestamp');

        return max(0, $now - $time);
    }

    public static function getCurrentMySQLTimestamp(): string
    {
        return current_time('mysql');
    }
}
