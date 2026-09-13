<?php
/**
 * The PROD pipeline publishes without writing to `main` (issue #208).
 *
 * `main` is protected with enforce_admins, so the old version bump — commit the
 * new version, push it to `main` — came back GH006 on every merge from
 * 2026-08-16 on, and `release` and `svn-deploy` never ran: WordPress.org stayed
 * on 3.2.0. The version now comes from the latest tag and is stamped into the
 * package copy by .github/scripts/release_version.py.
 *
 * Two halves: the workflow must never regain a write to `main` (read as text,
 * the only way to see it without running PROD), and the script must derive,
 * stamp and verify correctly (run for real against a throwaway git repository).
 */

use PHPUnit\Framework\TestCase;

class ReleasePipelineContractTest extends TestCase {

	private const ROOT = __DIR__ . '/../..';

	private string $repo = '';

	protected function tearDown(): void {
		foreach ( array( $this->repo, $this->repo . '-out' ) as $dir ) {
			if ( '' !== $this->repo && is_dir( $dir ) ) {
				$this->run_command( array( 'rm', '-rf', $dir ), sys_get_temp_dir() );
			}
		}
		parent::tearDown();
	}

	// ── The workflow ────────────────────────────────────────────────────────

	private function workflow(): string {
		return (string) file_get_contents( self::ROOT . '/.github/workflows/prod.yml' );
	}

	/** The text of one top-level job, up to the next job. */
	private function job( string $name ): string {
		$this->assertSame( 1, preg_match( '/^  ' . preg_quote( $name, '/' ) . ":\n(.*?)(?=^  [a-z_-]+:\n|\z)/ms", $this->workflow(), $m ), "job {$name} not found" );
		return $m[1];
	}

	public function test_no_step_writes_to_main(): void {
		$yaml = $this->workflow();

		foreach ( array(
			'/git\s+push\b[^\n]*\b(main|HEAD:main|refs\/heads\/main)\b/' => 'a push to main',
			'/git\s+commit\b/'                                          => 'a commit (there is nothing to commit any more)',
			'/git\s+revert\b/'                                          => 'a revert pushed back to main',
			'/git\s+pull\b/'                                            => 'a pull that would move off the tested commit',
			'/^\s*ref:\s*main\s*$/m'                                    => 'a checkout of the tip of main instead of the run commit',
		) as $pattern => $what ) {
			$this->assertSame( 0, preg_match( $pattern, $yaml ), "prod.yml regained {$what}" );
		}
	}

	public function test_the_version_job_cannot_write_contents(): void {
		$job = $this->job( 'version_bump' );

		$this->assertMatchesRegularExpression( '/permissions:\s*\n\s*contents:\s*read\s*\n/', $job );
		$this->assertStringNotContainsString( 'contents: write', $job );
	}

	public function test_the_tag_and_the_published_copy_are_the_commit_this_run_tested(): void {
		$release = $this->job( 'release' );
		$svn     = $this->job( 'svn-deploy' );

		$this->assertStringContainsString( 'git tag -a "${VERSION}" -F "${RUNNER_TEMP}/release-stamp/tag-message.txt" "${GITHUB_SHA}"', $release );
		$this->assertStringContainsString( 'name: release-stamp', $release );
		$this->assertStringContainsString( 'name: release-stamp', $svn );
		$this->assertStringContainsString( 'if [ "${TAGGED}" != "${GITHUB_SHA}" ]; then', $svn );
		$this->assertStringContainsString( 'release_version.py verify', $svn );
	}

	public function test_the_rollback_deletes_only_what_the_release_created(): void {
		$release = $this->job( 'release' );

		$this->assertStringContainsString( "if: failure() && env.tag_created == 'true'", $release );
		$this->assertStringContainsString( 'git push origin --delete "refs/tags/${VERSION}"', $release );
	}

	// ── The script ──────────────────────────────────────────────────────────

	/** @return array{0:int,1:string,2:string} */
	private function run_command( array $command, string $cwd ): array {
		$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $cwd );
		$this->assertIsResource( $process, 'could not start ' . $command[0] );
		$out = stream_get_contents( $pipes[1] );
		$err = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return array( proc_close( $process ), (string) $out, (string) $err );
	}

	private function git( string ...$args ): string {
		[ $code, $out, $err ] = $this->run_command( array_merge( array( 'git' ), $args ), $this->repo );
		$this->assertSame( 0, $code, 'git ' . implode( ' ', $args ) . ": {$err}" );
		return $out;
	}

	/** @return array{0:int,1:string,2:string} */
	private function script( string ...$args ): array {
		return $this->run_command(
			array_merge( array( 'python3', realpath( self::ROOT . '/.github/scripts/release_version.py' ) ), $args ),
			$this->repo
		);
	}

	private function commit( string $message ): void {
		$this->git( 'commit', '--allow-empty', '-q', '-m', $message );
	}

	private function write( string $file, string $content ): void {
		file_put_contents( $this->repo . '/' . $file, $content );
	}

	private function read( string $file ): string {
		return (string) file_get_contents( $this->repo . '/' . $file );
	}

	/** A repository shaped like this plugin, published once as v1.0.0 by the old flow. */
	private function published_repository(): void {
		foreach ( array( 'git', 'python3' ) as $binary ) {
			[ $code ] = $this->run_command( array( 'sh', '-c', "command -v {$binary}" ), sys_get_temp_dir() );
			if ( 0 !== $code ) {
				$this->fail( "{$binary} is required to exercise the release script" );
			}
		}
		$this->repo = sys_get_temp_dir() . '/contai-release-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->repo );
		$this->git( 'init', '-q', '-b', 'main' );
		foreach ( array( 'user.name=t', 'user.email=t@example.com', 'commit.gpgsign=false', 'tag.gpgsign=false' ) as $setting ) {
			$this->git( 'config', ...explode( '=', $setting, 2 ) );
		}
		$this->write( '1platform-content-ai.php', "<?php\n/**\n * Plugin Name: Test\n * Version: 1.0.0\n */\n" );
		$this->write( 'readme.txt', "=== Test ===\nStable tag: 1.0.0\n\n== Description ==\nx\n\n== Changelog ==\n\n= 1.0.0 =\n* Added: first (#1)\n" );
		$this->git( 'add', '1platform-content-ai.php', 'readme.txt' );
		$this->commit( 'chore: bump version to 1.0.0 (minor)' );
		$this->git( 'tag', '-a', 'v1.0.0', '-m', 'Release v1.0.0' );
	}

	/** @return array<string,string> */
	private function resolve(): array {
		[ $code, $out, $err ] = $this->script( 'resolve' );
		$this->assertSame( 0, $code, $err );
		$values = array();
		foreach ( array_filter( explode( "\n", $out ) ) as $line ) {
			[ $key, $value ]  = explode( '=', $line, 2 );
			$values[ $key ] = $value;
		}
		return $values;
	}

	private function stamp( string $version ): string {
		$out_dir = $this->repo . '-out';
		[ $code, , $err ] = $this->script( 'stamp', '--version', $version, '--out-dir', $out_dir );
		$this->assertSame( 0, $code, $err );
		[ $code, , $err ] = $this->script( 'verify', '--version', $version );
		$this->assertSame( 0, $code, $err );
		return $out_dir;
	}

	/**
	 * @dataProvider bumps
	 */
	public function test_the_new_version_is_the_strongest_signal_since_the_latest_tag( array $subjects, string $bump, string $version ): void {
		$this->published_repository();
		foreach ( $subjects as $subject ) {
			$this->commit( $subject );
		}

		$resolved = $this->resolve();

		$this->assertSame( array( 'already_released' => 'false', 'new_version' => $version, 'bump_type' => $bump, 'prev_tag' => 'v1.0.0' ), $resolved );
	}

	public static function bumps(): array {
		return array(
			'fix'                         => array( array( 'fix(seo): a (#2)' ), 'patch', '1.0.1' ),
			'ci only'                     => array( array( 'ci(sonar): a (#2)' ), 'patch', '1.0.1' ),
			'a feat among fixes'          => array( array( 'fix: a (#2)', 'feat(x): b (#3)', 'fix: c (#4)' ), 'minor', '1.1.0' ),
			'bang'                        => array( array( 'feat(api)!: a (#2)' ), 'major', '2.0.0' ),
			'breaking trailer in the body' => array( array( "fix: a (#2)\n\nBREAKING CHANGE: the option was renamed" ), 'major', '2.0.0' ),
		);
	}

	public function test_a_commit_that_is_already_published_does_not_get_a_second_version(): void {
		$this->published_repository();
		$this->commit( 'fix: a (#2)' );
		$this->git( 'tag', '-a', 'v1.0.1', '-m', 'Release v1.0.1' );

		$this->assertSame( 'true', $this->resolve()['already_released'] );
		$this->assertSame( '1.0.1', $this->resolve()['new_version'] );
	}

	public function test_stamping_writes_the_header_the_stable_tag_and_the_rendered_changelog(): void {
		$this->published_repository();
		$this->commit( 'fix(seo): titles keep their accents (#2)' );
		$this->commit( 'ci(sonar): only pull requests are analysed (#3)' );

		$out_dir = $this->stamp( '1.0.1' );

		$this->assertStringContainsString( ' * Version: 1.0.1', $this->read( '1platform-content-ai.php' ) );
		$this->assertStringContainsString( "Stable tag: 1.0.1\n", $this->read( 'readme.txt' ) );
		$this->assertStringContainsString( "== Changelog ==\n\n= 1.0.1 =\n* Fixed: titles keep their accents (#2)\n\n= 1.0.0 =\n", $this->read( 'readme.txt' ) );
		$this->assertStringNotContainsString( 'sonar', $this->read( 'readme.txt' ), 'a ci change is not a line users read' );
		$this->assertSame( "Release v1.0.1\n\n* Fixed: titles keep their accents (#2)\n", file_get_contents( $out_dir . '/tag-message.txt' ) );
		$this->assertSame( '', $this->git( 'diff', '--cached', '--name-only' ), 'stamping never stages, let alone commits' );
	}

	public function test_a_merge_that_wrote_its_own_entry_is_not_listed_twice(): void {
		$this->published_repository();
		$this->write( 'readme.txt', str_replace( "= 1.0.0 =\n", "= 1.0.1 =\n* Security: the key is public by design (#5)\n\n= 1.0.0 =\n", $this->read( 'readme.txt' ) ) );
		$this->git( 'add', 'readme.txt' );
		$this->commit( 'fix(config): the app key is public (#6)' );

		$this->stamp( '1.0.1' );

		$this->assertSame( 1, substr_count( $this->read( 'readme.txt' ), '= 1.0.1 =' ) );
		$this->assertStringContainsString( "= 1.0.1 =\n* Security: the key is public by design (#5)\n\n", $this->read( 'readme.txt' ) );
		$this->assertStringNotContainsString( '(#6)', $this->read( 'readme.txt' ) );
	}

	public function test_a_release_with_nothing_user_facing_still_gets_a_line(): void {
		$this->published_repository();
		$this->commit( 'ci: pin an action (#2)' );

		$this->stamp( '1.0.1' );

		$this->assertStringContainsString( "= 1.0.1 =\n* Changed: Maintenance release.\n", $this->read( 'readme.txt' ) );
	}

	public function test_the_history_of_uncommitted_releases_comes_back_from_their_tags(): void {
		$this->published_repository();
		$this->commit( 'feat(seo): a calendar (#2)' );
		$out_dir = $this->stamp( '1.1.0' );
		$this->git( 'tag', '-a', 'v1.1.0', '-F', $out_dir . '/tag-message.txt' );
		$this->git( 'checkout', '-q', '--', '1platform-content-ai.php', 'readme.txt' );

		$this->commit( 'fix: b (#3)' );
		$this->stamp( '1.1.1' );

		$this->assertStringContainsString(
			"== Changelog ==\n\n= 1.1.1 =\n* Fixed: b (#3)\n\n= 1.1.0 =\n* Added: a calendar (#2)\n\n= 1.0.0 =\n* Added: first (#1)\n",
			$this->read( 'readme.txt' )
		);
	}

	public function test_verify_rejects_a_package_that_does_not_carry_the_version(): void {
		$this->published_repository();
		$this->commit( 'fix: a (#2)' );
		$this->stamp( '1.0.1' );
		$this->write( '1platform-content-ai.php', "<?php\n/**\n * Plugin Name: Test\n * Version: 1.0.0\n */\n" );

		[ $code, , $err ] = $this->script( 'verify', '--version', '1.0.1' );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'plugin header carries 1.0.0, expected 1.0.1', $err );
	}
}
