<?php
/**
 * The entry point a site created by 1Platform uses to set itself up.
 *
 * The cases that matter are the ones with money or credentials behind them: a
 * key that does not authenticate must not be left behind claiming the site is
 * connected, and a second run must not queue (and bill for) a second build.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/services/bootstrap/RemoteBootstrapService.php';

class RemoteBootstrapServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( 'sanitize_text_field', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( '__', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( 'current_time', array( 'return' => '2026-08-07 10:00:00' ) );
		WP_Mock::userFunction( 'update_option', array( 'return' => true ) );
		WP_Mock::userFunction( 'contai_log', array( 'return' => null ) );
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/** A profile double that records what it was handed. */
	private function profile( bool $save = true, bool $refresh = true ) {
		$profile = $this->getMockBuilder( ContaiUserProfileService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'saveApiKey', 'refreshUserProfile', 'deleteApiKey' ) )
			->getMock();
		$profile->method( 'saveApiKey' )->willReturn( $save );
		$profile->method( 'refreshUserProfile' )->willReturn(
			array( 'success' => $refresh, 'message' => 'nope' )
		);
		return $profile;
	}

	private function auth() {
		return $this->getMockBuilder( ContaiOnePlatformAuthService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'clearUserToken' ) )
			->getMock();
	}

	private function websites() {
		return $this->getMockBuilder( ContaiWebsiteProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'ensureWebsiteExists' ) )
			->getMock();
	}

	private function jobs( $active = null, bool $created = true ) {
		$jobs = $this->getMockBuilder( ContaiJobRepository::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'findActiveSiteGenerationJob', 'create' ) )
			->getMock();
		$jobs->method( 'findActiveSiteGenerationJob' )->willReturn( $active );
		$jobs->method( 'create' )->willReturn( $created );
		return $jobs;
	}

	private function config(): array {
		return array(
			'site_config' => array(
				'site_topic'    => 'Clinicas',
				'site_language' => 'spanish',
				'site_category' => 'health',
			),
			'adsense'     => array( 'publisher_id' => '' ),
		);
	}

	public function test_it_stores_the_key_and_queues_the_build(): void {
		$jobs = $this->jobs();
		// The build is queued exactly once.
		$jobs->expects( $this->once() )->method( 'create' );

		$service = new ContaiRemoteBootstrapService(
			$this->profile(), $jobs, $this->websites(), $this->auth(), 12
		);
		$result = $service->run(
			array( 'api_key' => 'sk-live-key', 'config' => $this->config() )
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'generation', $result['step'] );
	}

	public function test_the_stale_user_token_is_cleared_before_the_profile_is_read(): void {
		// A token minted for a PREVIOUS key stays valid-looking, so skipping
		// this makes the site authenticate as whoever owned it before.
		$auth = $this->auth();
		$auth->expects( $this->once() )->method( 'clearUserToken' );

		$service = new ContaiRemoteBootstrapService(
			$this->profile(), $this->jobs(), $this->websites(), $auth, 12
		);
		$service->run( array( 'api_key' => 'sk-live-key', 'config' => $this->config() ) );
	}

	public function test_a_key_that_does_not_authenticate_is_removed_and_nothing_is_queued(): void {
		// Leaving it behind would show "connected" on the licence screen
		// forever, over a key that authenticates as nobody.
		$profile = $this->profile( true, false );
		$profile->expects( $this->once() )->method( 'deleteApiKey' );
		$jobs = $this->jobs();
		$jobs->expects( $this->never() )->method( 'create' );

		$service = new ContaiRemoteBootstrapService(
			$profile, $jobs, $this->websites(), $this->auth(), 12
		);
		$result = $service->run(
			array( 'api_key' => 'sk-bad', 'config' => $this->config() )
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'profile', $result['step'] );
	}

	public function test_a_second_run_does_not_queue_a_second_build(): void {
		// The retried provision, or a second visit racing the first. Queueing
		// again would bill the owner twice for one site.
		$jobs = $this->jobs( array( 'id' => 7 ) );
		$jobs->expects( $this->never() )->method( 'create' );

		$service = new ContaiRemoteBootstrapService(
			$this->profile(), $jobs, $this->websites(), $this->auth(), 12
		);
		$result = $service->run(
			array( 'api_key' => 'sk-live-key', 'config' => $this->config() )
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'generation', $result['step'] );
	}

	public function test_an_empty_key_is_refused_before_anything_is_touched(): void {
		$profile = $this->profile();
		$profile->expects( $this->never() )->method( 'saveApiKey' );

		$service = new ContaiRemoteBootstrapService(
			$profile, $this->jobs(), $this->websites(), $this->auth(), 12
		);
		$result = $service->run( array( 'api_key' => '', 'config' => $this->config() ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'api_key', $result['step'] );
	}

	public function test_a_licence_with_no_configuration_is_a_success_not_a_failure(): void {
		// A licensed site with no build queued is a legitimate outcome: the
		// owner can still run the wizard by hand.
		$jobs = $this->jobs();
		$jobs->expects( $this->never() )->method( 'create' );

		$service = new ContaiRemoteBootstrapService(
			$this->profile(), $jobs, $this->websites(), $this->auth(), 12
		);
		$result = $service->run( array( 'api_key' => 'sk-live-key', 'config' => array() ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'licence', $result['step'] );
	}

	public function test_the_neutral_image_code_is_translated_for_the_generator(): void {
		// The platform's payload carries a neutral code because its API contract
		// is published and a provider name there is a provider name in the public
		// OpenAPI document. The generator takes the provider value, so the
		// translation has to happen on this side.
		$captured = null;
		$jobs     = $this->getMockBuilder( ContaiJobRepository::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'findActiveSiteGenerationJob', 'create' ) )
			->getMock();
		$jobs->method( 'findActiveSiteGenerationJob' )->willReturn( null );
		$jobs->method( 'create' )->willReturnCallback(
			function ( $job ) use ( &$captured ) {
				$raw      = $job->getPayload();
				$captured = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
				return true;
			}
		);

		$config = $this->config();
		$config['post_generation'] = array( 'num_posts' => 10, 'image_source' => 'stock_images' );

		$service = new ContaiRemoteBootstrapService(
			$this->profile(), $jobs, $this->websites(), $this->auth(), 12
		);
		$service->run( array( 'api_key' => 'sk-live-key', 'config' => $config ) );

		$this->assertSame( 'pixabay', $captured['config']['post_generation']['image_provider'] );
		$this->assertArrayNotHasKey( 'image_source', $captured['config']['post_generation'] );
	}
}
