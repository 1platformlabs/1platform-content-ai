<?php
/**
 * REST controller for onboarding (self-service registration).
 *
 * Routes:
 *   POST /wp-json/contai/v1/onboarding/register
 *   GET  /wp-json/contai/v1/onboarding/status/<session_id>
 *
 * Both routes require manage_options capability and are hidden from
 * the REST API index (show_in_index => false).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/ContaiOnboardingService.php';

class ContaiOnboardingRestController {

    private string $namespace = 'contai/v1';
    private ContaiOnboardingService $service;

    public function __construct( ?ContaiOnboardingService $service = null ) {
        $this->service = $service ?? new ContaiOnboardingService();
    }

    /**
     * Register REST routes. Called via rest_api_init hook.
     */
    public function register_routes(): void {
        register_rest_route( $this->namespace, '/onboarding/register', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_register' ),
            'permission_callback' => array( $this, 'check_permissions' ),
            'show_in_index'       => false,
            'args'                => array(
                'email' => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_email',
                    'validate_callback' => function( $value ) {
                        return is_email( $value );
                    },
                ),
                'amount' => array(
                    'required'          => true,
                    'sanitize_callback' => 'floatval',
                    'validate_callback' => function( $value ) {
                        return is_numeric( $value ) && floatval( $value ) > 0 && floatval( $value ) <= 10000;
                    },
                ),
                'currency' => array(
                    'required'          => false,
                    'default'           => 'USD',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function( $value ) {
                        return preg_match( '/^[A-Z]{3}$/', $value );
                    },
                ),
            ),
        ) );

        register_rest_route( $this->namespace, '/onboarding/status/(?P<session_id>[a-f0-9\-]+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_status' ),
            'permission_callback' => array( $this, 'check_permissions' ),
            'show_in_index'       => false,
            'args'                => array(
                'session_id' => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function( $value ) {
                        return (bool) preg_match( '/^[a-f0-9\-]{36}$/', $value );
                    },
                ),
            ),
        ) );
    }

    /**
     * Permission check — requires manage_options.
     */
    public function check_permissions(): bool {
        return current_user_can( 'manage_options' );
    }

    /**
     * Handle POST /onboarding/register
     */
    public function handle_register( WP_REST_Request $request ): WP_REST_Response {
        $email    = sanitize_email( $request->get_param( 'email' ) );
        $amount   = floatval( $request->get_param( 'amount' ) );
        $currency = sanitize_text_field( $request->get_param( 'currency' ) );

        if ( ! is_email( $email ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => esc_html__( 'Invalid email address.', '1platform-content-ai' ),
            ), 400 );
        }

        if ( $amount < 5.0 || $amount > 10000.0 ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => esc_html__( 'Amount must be between $5.00 and $10,000.00 USD.', '1platform-content-ai' ),
            ), 400 );
        }

        $response = $this->service->createRegistration( $email, $amount, $currency );

        if ( $response->isSuccess() ) {
            $data = $response->getData();
            $session_id = isset( $data->session_id ) ? $data->session_id : ( isset( $data['session_id'] ) ? $data['session_id'] : '' );

            // Save session_id in transient for recovery after tab close (scoped per user)
            if ( $session_id ) {
                $transient_key = 'contai_onboarding_session_' . get_current_user_id();
                set_transient( $transient_key, sanitize_text_field( $session_id ), DAY_IN_SECONDS );
            }

            $payment_url = isset( $data->payment_url ) ? $data->payment_url : ( isset( $data['payment_url'] ) ? $data['payment_url'] : '' );

            return new WP_REST_Response( array(
                'success' => true,
                'data'    => array(
                    'session_id'  => sanitize_text_field( $session_id ),
                    'payment_url' => esc_url_raw( $payment_url ),
                ),
                'message' => wp_kses_post( $response->getMessage() ),
            ), 201 );
        }

        $status_code = $response->getStatusCode();
        $http_code   = ( $status_code >= 400 && $status_code < 600 ) ? $status_code : 500;

        return new WP_REST_Response( array(
            'success' => false,
            'message' => wp_kses_post( $response->getMessage() ) ?: esc_html__( 'Registration failed. Please try again.', '1platform-content-ai' ),
        ), $http_code );
    }

    /**
     * Handle GET /onboarding/status/{session_id}
     */
    public function handle_status( WP_REST_Request $request ): WP_REST_Response {
        $session_id = sanitize_text_field( $request->get_param( 'session_id' ) );

        $response = $this->service->checkStatus( $session_id );

        if ( $response->isSuccess() ) {
            $data = $response->getData();
            $status  = isset( $data->status )  ? sanitize_text_field( $data->status )  : ( isset( $data['status'] )  ? sanitize_text_field( $data['status'] )  : '' );
            $api_key = isset( $data->api_key ) ? sanitize_text_field( $data->api_key ) : ( isset( $data['api_key'] ) ? sanitize_text_field( $data['api_key'] ) : '' );

            return new WP_REST_Response( array(
                'success' => true,
                'data'    => array(
                    'status'  => $status,
                    'api_key' => $api_key,
                ),
                'message' => wp_kses_post( $response->getMessage() ),
            ), 200 );
        }

        $status_code = $response->getStatusCode();
        $http_code   = ( $status_code >= 400 && $status_code < 600 ) ? $status_code : 500;

        return new WP_REST_Response( array(
            'success' => false,
            'message' => wp_kses_post( $response->getMessage() ) ?: esc_html__( 'Failed to check status.', '1platform-content-ai' ),
        ), $http_code );
    }
}
