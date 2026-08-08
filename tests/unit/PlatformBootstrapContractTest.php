<?php
/**
 * The payload the platform really sends, against the payload this plugin builds.
 *
 * Two repositories, one shape, and nothing in either CI checks the other. The
 * fixture is not hand-written: it is the body a live redemption of
 * `POST /provisioning/wp-bootstrap/claim` returned, captured verbatim (licence
 * redacted, fixture labels neutralised). Hand-writing it would test this file
 * against itself, which is exactly the failure mode worth guarding — the two
 * sides drifted once already, on `image_provider`.
 *
 * Re-capture it when the platform's contract changes; a fixture that no longer
 * matches what the platform sends is the thing this test exists to catch.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/services/bootstrap/RemoteBootstrapService.php';

class PlatformBootstrapContractTest extends TestCase {

	private array $payload;

	protected function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( 'sanitize_text_field', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( '__', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( 'current_time', array( 'return' => '2026-08-07 10:00:00' ) );
		WP_Mock::userFunction( 'update_option', array( 'return' => true ) );
		WP_Mock::userFunction( 'contai_log', array( 'return' => null ) );

		$raw = file_get_contents( __DIR__ . '/../fixtures/platform-bootstrap-payload.json' );
		$this->payload = json_decode( $raw, true );
		$this->assertIsArray( $this->payload, 'the captured payload must parse' );
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/** Reach the private builder — it is what the job runner consumes. */
	private function build( array $config ): array {
		$service = new ContaiRemoteBootstrapService(
			$this->createMock( ContaiUserProfileService::class ),
			$this->createMock( ContaiJobRepository::class ),
			$this->createMock( ContaiWebsiteProvider::class ),
			$this->createMock( ContaiOnePlatformAuthService::class ),
			12
		);
		$m = new ReflectionMethod( ContaiRemoteBootstrapService::class, 'buildJobPayload' );
		$m->setAccessible( true );
		return $m->invoke( $service, $config );
	}

	public function test_every_block_the_generator_reads_is_present(): void {
		$config = $this->build( $this->payload['config'] )['config'];

		foreach ( array( 'site_config', 'legal_info', 'keyword_extraction', 'post_generation', 'comments', 'adsense' ) as $block ) {
			$this->assertArrayHasKey( $block, $config, "the platform did not send `$block`" );
		}
		// Compared as SETS: JSON objects carry no order, so asserting the
		// sequence would fail on a re-serialisation that changed nothing.
		$this->assertEqualsCanonicalizing(
			array( 'site_topic', 'site_language', 'site_category', 'wordpress_theme' ),
			array_keys( $config['site_config'] )
		);
		$this->assertEqualsCanonicalizing(
			array( 'owner', 'email', 'address', 'activity' ),
			array_keys( $config['legal_info'] )
		);
		$this->assertEqualsCanonicalizing(
			array( 'source_topic', 'target_country', 'target_language' ),
			array_keys( $config['keyword_extraction'] )
		);
	}

	public function test_the_neutral_image_code_becomes_a_value_the_generator_takes(): void {
		// The platform's contract is published, so it cannot name the library.
		// The translation is this plugin's job, and getting it wrong produces a
		// build with no images rather than an error.
		$config = $this->build( $this->payload['config'] )['config'];

		$this->assertArrayHasKey( 'image_provider', $config['post_generation'] );
		$this->assertNotSame( '', $config['post_generation']['image_provider'] );
		$this->assertArrayNotHasKey(
			'image_source',
			$config['post_generation'],
			'the neutral code must be consumed, not passed through as well'
		);
	}

	public function test_the_post_count_reaches_both_blocks_that_read_it(): void {
		// It travels twice in the form's own payload; the comment step reads its
		// own copy, so a single-sourced value silently generates zero comments.
		$config = $this->build( $this->payload['config'] )['config'];

		$this->assertSame( 10, $config['post_generation']['num_posts'] );
		$this->assertSame( 10, $config['comments']['num_posts'] );
	}

	public function test_the_derived_language_is_not_the_language_name(): void {
		$config = $this->build( $this->payload['config'] )['config'];

		$this->assertSame( 'spanish', $config['site_config']['site_language'] );
		$this->assertSame( 'es', $config['keyword_extraction']['target_language'] );
		$this->assertSame( 'es', $config['post_generation']['target_language'] );
	}

	public function test_the_runner_is_told_how_many_steps_it_has(): void {
		// A job whose progress block says zero steps believes it is finished.
		$progress = $this->build( $this->payload['config'] )['progress'];

		$this->assertSame( 12, $progress['total_steps'] );
		$this->assertSame( 0, $progress['current_step'] );
		$this->assertSame( array(), $progress['completed_steps'] );
	}

	public function test_no_provider_name_arrives_from_the_platform(): void {
		// The platform's half of the contract: the wire format stays neutral.
		$wire = strtolower( json_encode( $this->payload ) );
		foreach ( array( 'pexels', 'pixabay', 'softaculous', 'cpanel' ) as $vendor ) {
			$this->assertStringNotContainsString( $vendor, $wire );
		}
	}
}
