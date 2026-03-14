<?php

if (!defined('ABSPATH')) exit;

class ContaiLegalInfoService
{
    public function saveLegalInfo(array $legalInfo): bool
    {
        $owner = $legalInfo['owner'] ?? '';
        $email = $legalInfo['email'] ?? '';
        $address = $legalInfo['address'] ?? '';
        $activity = $legalInfo['activity'] ?? '';

        if (!empty($email) && !is_email($email)) {
            throw new InvalidArgumentException('Invalid email format');
        }

        update_option('contai_legal_owner', sanitize_text_field($owner));
        update_option('contai_legal_email', sanitize_email($email));
        update_option('contai_legal_address', sanitize_text_field($address));
        update_option('contai_legal_activity', sanitize_text_field($activity));

        return true;
    }

    public function validateLegalInfo(array $legalInfo): array
    {
        $errors = [];

        if (empty($legalInfo['owner'])) {
            $errors[] = 'Owner name is required';
        }

        if (empty($legalInfo['email'])) {
            $errors[] = 'Contact email is required';
        } elseif (!is_email($legalInfo['email'])) {
            $errors[] = 'Invalid email format';
        }

        if (empty($legalInfo['address'])) {
            $errors[] = 'Fiscal address is required';
        }

        if (empty($legalInfo['activity'])) {
            $errors[] = 'Business activity is required';
        }

        return $errors;
    }

    public function getLegalInfo(): array
    {
        return [
            'owner' => get_option('contai_legal_owner', ''),
            'email' => get_option('contai_legal_email', ''),
            'address' => get_option('contai_legal_address', ''),
            'activity' => get_option('contai_legal_activity', ''),
        ];
    }
}
