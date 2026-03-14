<?php

if (!defined('ABSPATH')) exit;

interface ContaiJobInterface
{
    public function handle(array $payload);

    public function getType();
}
