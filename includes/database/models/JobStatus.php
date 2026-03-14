<?php

if (!defined('ABSPATH')) exit;

class ContaiJobStatus
{
    const PENDING = 'pending';
    const PROCESSING = 'processing';
    const DONE = 'done';
    const FAILED = 'failed';

    public static function isValid($status)
    {
        return in_array($status, self::all());
    }

    public static function all()
    {
        return [
            self::PENDING,
            self::PROCESSING,
            self::DONE,
            self::FAILED
        ];
    }
}
