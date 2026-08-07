<?php
/**
 * Programmatic entry point for a site 1Platform created.
 *
 * A site built by the platform arrives with nobody in front of it: there is no
 * administrator to paste a licence key or fill in the wizard. This runs the
 * exact same sequence the two admin screens run, in the same order, by calling
 * the same services — it does NOT reimplement either of them. The licence half
 * mirrors `WPContentAILicensePanel::handleActivateLicense()`; the wizard half
 * mirrors `contai_process_site_generation_submission()`.
 *
 * Why this exists at all: the key is encrypted with the site's own salt
 * (`contai_encrypt_api_key` → `wp_salt()`), so the ciphertext is different in
 * every installation and simply cannot be written from outside. Something has
 * to run *inside* WordPress, and this is the smallest thing that can.
 *
 * @package 1Platform_Content_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../user-profile/UserProfileService.php';
require_once __DIR__ . '/../api/OnePlatformAuthService.php';
require_once __DIR__ . '/../jobs/SiteGenerationJob.php';
require_once __DIR__ . '/../../database/models/Job.php';
require_once __DIR__ . '/../../database/repositories/JobRepository.php';
require_once __DIR__ . '/../../providers/WebsiteProvider.php';

class ContaiRemoteBootstrapService {

	/** @var ContaiUserProfileService */
	private $profile;

	/** @var ContaiJobRepository */
	private $jobs;

	/** @var ContaiWebsiteProvider */
	private $websites;

	/** @var ContaiOnePlatformAuthService|null */
	private $auth;

	/** @var int|null */
	private $step_count;

	public function __construct(
		?ContaiUserProfileService $profile = null,
		?ContaiJobRepository $jobs = null,
		?ContaiWebsiteProvider $websites = null,
		$auth = null,
		?int $step_count = null
	) {
		// Resolved lazily from the job class when absent. Injectable because
		// counting the steps otherwise means CONSTRUCTING the job runner, which
		// opens a database connection — a heavy dependency for a number, and one
		// that makes every path through this class need a live database.
		$this->step_count = $step_count;
		$this->profile  = $profile ?? new ContaiUserProfileService();
		$this->jobs     = $jobs ?? new ContaiJobRepository();
		$this->websites = $websites ?? new ContaiWebsiteProvider();
		// Injected rather than reached for statically inside `run()`: the stale
		// token clear is a step this class is responsible for getting right, and
		// a static call is a step no test can observe.
		$this->auth = $auth;
	}

	private function auth(): ContaiOnePlatformAuthService {
		if ( null === $this->auth ) {
			$this->auth = ContaiOnePlatformAuthService::create();
		}
		return $this->auth;
	}

	public static function create(): self {
		return new self();
	}

	/**
	 * Activate the licence and queue the site build.
	 *
	 * @param array $payload { api_key: string, config: array }.
	 * @return array { success: bool, step: string, message: string }.
	 */
	public function run( array $payload ): array {
		$api_key = isset( $payload['api_key'] ) ? sanitize_text_field( (string) $payload['api_key'] ) : '';
		if ( '' === $api_key ) {
			return $this->fail( 'api_key', __( 'A licence key is required.', '1platform-content-ai' ) );
		}

		// ── Licence, in the order the panel uses it.
		//
		// The stale-token clear is not optional and not cosmetic: a user token
		// minted for a PREVIOUS key stays valid-looking, so skipping this makes
		// the site authenticate as whoever owned the site before.
		if ( ! $this->profile->saveApiKey( $api_key ) ) {
			return $this->fail( 'api_key', __( 'The licence key could not be stored.', '1platform-content-ai' ) );
		}
		$this->auth()->clearUserToken();

		$profile = $this->profile->refreshUserProfile();
		if ( empty( $profile['success'] ) ) {
			// Leaving a key that does not authenticate would show "connected"
			// on the licence screen forever, so it is removed on failure —
			// exactly what the panel does.
			$this->profile->deleteApiKey();
			return $this->fail(
				'profile',
				isset( $profile['message'] ) ? (string) $profile['message'] : __( 'The licence key could not be validated.', '1platform-content-ai' )
			);
		}

		// Registers this site against the owner's account. This is also the
		// call that makes the platform's heartbeat able to find the site later.
		$this->websites->ensureWebsiteExists();

		// ── Wizard.
		$config = isset( $payload['config'] ) && is_array( $payload['config'] ) ? $payload['config'] : array();
		if ( empty( $config ) ) {
			// A licensed site with no build queued is a legitimate outcome, not
			// a failure: the owner can still run the wizard by hand.
			return $this->ok( 'licence', __( 'Licence activated.', '1platform-content-ai' ) );
		}

		// The same guard the form applies. Re-running the bootstrapper — a
		// retried provision, a second visit that raced the first — must not
		// queue a second build and bill the owner twice.
		if ( $this->jobs->findActiveSiteGenerationJob() ) {
			return $this->ok( 'generation', __( 'Site generation is already running.', '1platform-content-ai' ) );
		}

		$this->persistWizardOptions( $config );

		$job     = ContaiJob::create( ContaiSiteGenerationJob::TYPE, $this->buildJobPayload( $config ), 0 );
		$created = $this->jobs->create( $job );
		if ( ! $created ) {
			return $this->fail( 'generation', __( 'Site generation could not be started.', '1platform-content-ai' ) );
		}

		return $this->ok( 'generation', __( 'Site generation started.', '1platform-content-ai' ) );
	}

	/**
	 * The job payload, shaped exactly as the form builds it.
	 *
	 * The `progress` block is what the job runner reads to know where it is;
	 * omitting it starts a job that believes it has no steps.
	 */
	private function buildJobPayload( array $config ): array {
		return array(
			'config'   => $config,
			'progress' => array(
				'current_step'      => 0,
				'current_step_name' => '',
				'completed_steps'   => array(),
				'total_steps'       => $this->stepCount(),
				'started_at'        => current_time( 'mysql' ),
			),
		);
	}

	/**
	 * The options the form writes alongside the job.
	 *
	 * These are read by screens the job never touches, so a site configured
	 * only through the job would show its language and category as unset.
	 */
	private function persistWizardOptions( array $config ): void {
		$site = isset( $config['site_config'] ) && is_array( $config['site_config'] ) ? $config['site_config'] : array();
		if ( ! empty( $site['site_language'] ) ) {
			update_option( 'contai_site_language', sanitize_text_field( (string) $site['site_language'] ) );
		}
		if ( ! empty( $site['site_category'] ) ) {
			update_option( 'contai_site_category', sanitize_text_field( (string) $site['site_category'] ) );
		}

		$adsense   = isset( $config['adsense'] ) && is_array( $config['adsense'] ) ? $config['adsense'] : array();
		$publisher = isset( $adsense['publisher_id'] ) ? sanitize_text_field( (string) $adsense['publisher_id'] ) : '';
		// The same shape check the form applies before believing the value.
		if ( '' !== $publisher && preg_match( '/^pub-\d+$/', $publisher ) ) {
			update_option( 'contai_adsense_publishers', $publisher );
			if ( function_exists( 'contai_generate_adsense_ads' ) ) {
				contai_generate_adsense_ads();
			}
		}
	}

	/**
	 * How many steps the site build has.
	 *
	 * Read from the job class itself, never hard-coded: the runner reads this
	 * number back to know when it is finished, so a stale copy here would leave
	 * every build reporting the wrong progress.
	 */
	private function stepCount(): int {
		if ( null === $this->step_count ) {
			$this->step_count = ( new ContaiSiteGenerationJob() )->getStepCount();
		}
		return $this->step_count;
	}

	private function ok( string $step, string $message ): array {
		return array(
			'success' => true,
			'step'    => $step,
			'message' => $message,
		);
	}

	private function fail( string $step, string $message ): array {
		return array(
			'success' => false,
			'step'    => $step,
			'message' => $message,
		);
	}
}

if ( ! function_exists( 'contai_remote_bootstrap' ) ) {
	/**
	 * Activate this site's licence and queue its build, without an administrator.
	 *
	 * The single public entry point for a site 1Platform created. Deliberately a
	 * plain function: the caller is a must-use plugin that runs before anything
	 * else is loaded and has no way to reach a class it cannot autoload.
	 *
	 * Never echoes and never redirects — it is called from `init`, not from a
	 * form handler, and its caller deletes itself based on what it returns.
	 *
	 * @param array $payload { api_key: string, config: array }.
	 * @return array { success: bool, step: string, message: string }.
	 */
	function contai_remote_bootstrap( array $payload ): array {
		try {
			return ContaiRemoteBootstrapService::create()->run( $payload );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			contai_log( 'Remote bootstrap failed: ' . $e->getMessage() );
			return array(
				'success' => false,
				'step'    => 'unexpected',
				'message' => __( 'The site could not be set up automatically.', '1platform-content-ai' ),
			);
		}
	}
}
