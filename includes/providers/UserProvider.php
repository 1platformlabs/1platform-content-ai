<?php

if (!defined('ABSPATH')) exit;

class ContaiUserProvider
{
    private const OPTION_USER_PROFILE = 'contai_user_profile';

    public function getUserProfile(): ?array
    {
        $profile = get_option(self::OPTION_USER_PROFILE, null);

        if (!$profile) {
            return null;
        }

        return is_array($profile) ? $profile : json_decode($profile, true);
    }

    public function getUserId(): ?string
    {
        $profile = $this->getUserProfile();
        return $profile['userId'] ?? null;
    }
}
