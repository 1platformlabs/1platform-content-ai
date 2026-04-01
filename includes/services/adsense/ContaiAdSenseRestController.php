<?php
/**
 * REST API controller for AdSense integration.
 *
 * Proxies requests from the WP admin UI to the 1Platform API via
 * ContaiOnePlatformClient. All routes are registered under the 'contai/v1'
 * namespace and are restricted to users with 'manage_options' capability.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ContaiAdSenseRestController {

	private $namespace = 'contai/v1';

	public function register_routes(): void {

		register_rest_route( $this->namespace, '/adsense/authorize', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'authorize' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'show_in_index'       => false,
		) );

		register_rest_route( $this->namespace, '/adsense/connect', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'connect' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'show_in_index'       => false,
		) );

		register_rest_route( $this->namespace, '/adsense/disconnect', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'disconnect' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'show_in_index'       => false,
		) );

		register_rest_route( $this->namespace, '/adsense/revoke', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'revoke' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'show_in_index'       => false,
		) );

		register_rest_route( $this->namespace, '/adsense/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'status' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( $this->namespace, '/adsense/earnings', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'earnings' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'period' => array(
					'default'           => '7d',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function ( $value ) {
						$allowed = array( '7d', '28d', '30d', '90d', 'lifetime', 'LAST_7_DAYS', 'LAST_28_DAYS', 'LAST_30_DAYS', 'LAST_90_DAYS' );
						return in_array( $value, $allowed, true );
					},
				),
			),
		) );

		register_rest_route( $this->namespace, '/adsense/sites', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'sites' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( $this->namespace, '/adsense/sites/sync', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'sync_sites' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( $this->namespace, '/adsense/alerts', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'alerts' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( $this->namespace, '/adsense/policy-issues', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'policy_issues' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( $this->namespace, '/adsense/oauth-status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'oauth_status' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );
	}

	public function check_admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	// ── Handlers ──

	public function authorize( \WP_REST_Request $request ): \WP_REST_Response {
		$website_id = $this->get_website_id();
		if ( is_wp_error( $website_id ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => $website_id->get_error_message() ), 400 );
		}

		$api    = ContaiOnePlatformClient::create();
		$result = $api->get( '/adsense/oauth/authorize', array( 'website_id' => $website_id ) );

		return $this->apiResponse( $result );
	}

	public function connect( \WP_REST_Request $request ): \WP_REST_Response {
		$website_id = $this->get_website_id();
		if ( is_wp_error( $website_id ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => $website_id->get_error_message() ), 400 );
		}

		$api    = ContaiOnePlatformClient::create();
		$result = $api->post( '/adsense/connect', array(), array( 'website_id' => $website_id ) );

		if ( ! $result->isSuccess() ) {
			return $this->apiResponse( $result );
		}

		$data = $result->getData();

		if ( is_array( $data ) && ! empty( $data['publisher_id'] ) ) {
			$this->sync_publisher_id( $data['publisher_id'] );
		}

		update_option( 'contai_adsense_connected', true, false );

		return new \WP_REST_Response( array( 'success' => true, 'data' => $data ), 200 );
	}

	public function disconnect( \WP_REST_Request $request ): \WP_REST_Response {
		$website_id = $this->get_website_id();
		if ( is_wp_error( $website_id ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => $website_id->get_error_message() ), 400 );
		}

		$api    = ContaiOnePlatformClient::create();
		$result = $api->delete( '/adsense/disconnect', array( 'website_id' => $website_id ) );

		if ( $result->isSuccess() ) {
			update_option( 'contai_adsense_connected', false, false );
		}

		return $this->apiResponse( $result );
	}

	public function revoke( \WP_REST_Request $request ): \WP_REST_Response {
		$website_id = $this->get_website_id();
		if ( is_wp_error( $website_id ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => $website_id->get_error_message() ), 400 );
		}

		$api    = ContaiOnePlatformClient::create();
		$result = $api->post( '/adsense/oauth/revoke', array(), array( 'website_id' => $website_id ) );

		if ( $result->isSuccess() ) {
			update_option( 'contai_adsense_connected', false, false );
		}

		return $this->apiResponse( $result );
	}

	public function status( \WP_REST_Request $request ): \WP_REST_Response {
		$website_id = $this->get_website_id();
		if ( is_wp_error( $website_id ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => $website_id->get_error_message() ), 400 );
		}

		$api    = ContaiOnePlatformClient::create();
		$result = $api->get( '/adsense/status', array( 'website_id' => $website_id ) );

		return $this->apiResponse( $result );
	}

	public function earnings( \WP_REST_Request $request ): \WP_REST_Response {
		$website_id = $this->get_website_id();
		if ( is_wp_error( $website_id ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => $website_id->get_error_message() ), 400 );
		}

		$period = sanitize_text_field( $request->get_param( 'period' ) ?: '7d' );

		$api    = ContaiOnePlatformClient::create();
		$result = $api->get( '/adsense/earnings/overview', array(
			'website_id' => $website_id,
			'period'     => $period,
			'compare'    => 'true',
		) );

		return $this->apiResponse( $result );
	}

	public function sites( \WP_REST_Request $request ): \WP_REST_Response {
		$website_id = $this->get_website_id();
		if ( is_wp_error( $website_id ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => $website_id->get_error_message() ), 400 );
		}

		$api    = ContaiOnePlatformClient::create();
		$result = $api->get( '/adsense/sites', array( 'website_id' => $website_id ) );

		return $this->apiResponse( $result );
	}

	public function sync_sites( \WP_REST_Request $request ): \WP_REST_Response {
		$website_id = $this->get_website_id();
		if ( is_wp_error( $website_id ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => $website_id->get_error_message() ), 400 );
		}

		$api    = ContaiOnePlatformClient::create();
		$result = $api->post( '/adsense/sites/sync', array(), array( 'website_id' => $website_id ) );

		return $this->apiResponse( $result );
	}

	public function alerts( \WP_REST_Request $request ): \WP_REST_Response {
		$website_id = $this->get_website_id();
		if ( is_wp_error( $website_id ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => $website_id->get_error_message() ), 400 );
		}

		$api    = ContaiOnePlatformClient::create();
		$result = $api->get( '/adsense/alerts', array( 'website_id' => $website_id ) );

		return $this->apiResponse( $result );
	}

	public function policy_issues( \WP_REST_Request $request ): \WP_REST_Response {
		$website_id = $this->get_website_id();
		if ( is_wp_error( $website_id ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => $website_id->get_error_message() ), 400 );
		}

		$api    = ContaiOnePlatformClient::create();
		$result = $api->get( '/adsense/policy-issues', array( 'website_id' => $website_id ) );

		return $this->apiResponse( $result );
	}

	public function oauth_status( \WP_REST_Request $request ): \WP_REST_Response {
		$website_id = $this->get_website_id();
		if ( is_wp_error( $website_id ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => $website_id->get_error_message() ), 400 );
		}

		$api    = ContaiOnePlatformClient::create();
		$result = $api->get( '/adsense/oauth/status', array( 'website_id' => $website_id ) );

		return $this->apiResponse( $result );
	}

	// ── Private Helpers ──

	private function apiResponse( ContaiOnePlatformResponse $result ): \WP_REST_Response {
		if ( ! $result->isSuccess() ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => $result->getMessage() ), 502 );
		}
		return new \WP_REST_Response( array( 'success' => true, 'data' => $result->getData() ), 200 );
	}

	private function get_website_id() {
		$provider   = new ContaiWebsiteProvider();
		$website_id = $provider->getWebsiteId();
		if ( empty( $website_id ) ) {
			return new \WP_Error( 'no_website', 'No website configured. Complete the initial setup first.' );
		}
		return $website_id;
	}

	private function sync_publisher_id( string $publisher_id ): void {
		if ( ! preg_match( '/^pub-\d{10,20}$/', $publisher_id ) ) {
			return;
		}

		$current = get_option( 'contai_adsense_publisher_id', '' );
		if ( $current !== $publisher_id ) {
			update_option( 'contai_adsense_publisher_id', $publisher_id, false );
		}

		$publishers = get_option( 'contai_adsense_publishers', '' );
		if ( strpos( $publishers, $publisher_id ) === false ) {
			$publishers = trim( $publishers . "\n" . $publisher_id );
			update_option( 'contai_adsense_publishers', $publishers );
			contai_generate_adsense_ads();
		}
	}
}
