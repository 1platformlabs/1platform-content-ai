<?php

if (!defined('ABSPATH')) exit;

interface ContaiJobRecoveryStrategy
{
    public function shouldRecover(ContaiJob $job): bool;

    public function recover(ContaiJob $job): void;
}
