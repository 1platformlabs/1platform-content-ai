<?php
/**
 * Onboarding service for self-service registration via payment.
 *
 * Proxies registration and status-polling calls to the 1Platform API.
 * Uses app-token-only authentication (no user token — user doesn't exist yet).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/../api/OnePlatformClient.php';
require_once __DIR__ . '/../api/OnePlatformEndpoints.php';

class ContaiOnboardingService {

    private ContaiOnePlatformClient $client;

    public function __construct( ?ContaiOnePlatformClient $client = null ) {
        $this->client = $client ?? ContaiOnePlatformClient::create();
    }

    /**
     * Create a registration session via the API.
     *
     * @param string $email    User email address.
     * @param float  $amount   Payment amount.
     * @param string $currency ISO 4217 currency code.
     * @return ContaiOnePlatformResponse
     */
    public function createRegistration( string $email, float $amount, string $currency = 'USD' ): ContaiOnePlatformResponse {
        return $this->client->post(
            ContaiOnePlatformEndpoints::ONBOARDING_REGISTER,
            array(
                'email'    => $email,
                'amount'   => $amount,
                'currency' => $currency,
            )
        );
    }

    /**
     * Check the status of a registration session.
     *
     * @param string $session_id UUID session ID.
     * @return ContaiOnePlatformResponse
     */
    public function checkStatus( string $session_id ): ContaiOnePlatformResponse {
        return $this->client->get(
            ContaiOnePlatformEndpoints::onboardingStatus( $session_id )
        );
    }
}
