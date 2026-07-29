<?php
/**
 * REST controller for the Search Console performance panel (SCP-04/SCP-05).
 *
 * Proxies the two FREE read endpoints of the 1Platform API through
 * ContaiSearchConsoleService. Registered under the 'contai/v1' namespace and
 * restricted to 'manage_options'.
 *
 * Deliberately REST rather than the admin_init form flow the rest of the
 * Search Console panel uses: the date-range picker has to refetch without
 * reloading the page, which a form post cannot do.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/SearchConsoleService.php';

class ContaiSearchConsolePerformanceRestController {

	private $namespace = 'contai/v1';

	/** The four presets the API accepts. Anything else is rejected at the edge. */
	private const ALLOWED_PERIODS = array( '24h', '7d', '28d', '3m' );

	private ?ContaiSearchConsoleService $service;

	public function __construct( ?ContaiSearchConsoleService $service = null ) {
		$this->service = $service;
	}

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/search-console/performance', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'performance' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'period' => array(
					'default'           => '28d',
					'sanitize_callback' => 'sanitize_text_field',
					// validate_callback, not just sanitize: sanitising a bad
					// value would forward it to the API and get a 422 back.
					'validate_callback' => function ( $value ) {
						return in_array( $value, self::ALLOWED_PERIODS, true );
					},
				),
			),
		) );

		register_rest_route( $this->namespace, '/search-console/sitemaps', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'sitemaps' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );
	}

	public function check_admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public function performance( \WP_REST_Request $request ): \WP_REST_Response {
		$period = sanitize_text_field( $request->get_param( 'period' ) ?: '28d' );
		if ( ! in_array( $period, self::ALLOWED_PERIODS, true ) ) {
			$period = '28d';
		}

		return $this->apiResponse( $this->service()->getPerformance( $period ) );
	}

	public function sitemaps( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->apiResponse( $this->service()->getSitemapsStatus() );
	}

	private function service(): ContaiSearchConsoleService {
		if ( null === $this->service ) {
			$this->service = new ContaiSearchConsoleService();
		}
		return $this->service;
	}

	private function apiResponse( ContaiOnePlatformResponse $result ): \WP_REST_Response {
		if ( ! $result->isSuccess() ) {
			// The upstream status is preserved where it is meaningful to the
			// panel — 409 (not verified) and 429 (quota) are states the UI
			// renders differently from a generic upstream failure.
			$status = $result->getStatusCode();
			$status = in_array( $status, array( 400, 404, 409, 429 ), true ) ? $status : 502;
			return new \WP_REST_Response(
				array( 'success' => false, 'message' => $result->getMessage() ),
				$status
			);
		}
		return new \WP_REST_Response( array( 'success' => true, 'data' => $result->getData() ), 200 );
	}
}
