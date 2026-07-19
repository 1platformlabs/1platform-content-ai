<?php

namespace ContAI\Tests\Unit\Services\Jobs;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiSiteGenerationJob;
use ContaiJobRepository;
use ContaiDatabase;

/**
 * A failing "optional" wizard step must leave a trace, and must not kill the
 * run (#48).
 *
 * The four optional steps — generateComments, setupSearchConsole,
 * setupAdsManager, setupNavigation — used to catch(Exception) and hand the
 * message to contai_log(), which writes only when WP_DEBUG is true
 * (includes/helpers/crypto.php). Production installs run with WP_DEBUG off, so
 * the failure went nowhere at all: handle() still appended the step to
 * completed_steps, still returned "Site generation completed successfully" and
 * still set contai_site_generation_completed. A site could finish the wizard
 * reporting success with no primary menu, no categories in the menu and no
 * comments — which is why this issue kept reopening with nothing to diagnose
 * from.
 *
 * catch(Exception) was also the wrong net: a PHP Error is not an Exception, so
 * it escaped and was rethrown by handle(), aborting the whole generation — the
 * opposite of what "optional" is supposed to buy.
 */
class OptionalStepFailureTest extends TestCase
{
    private ContaiSiteGenerationJob $job;

    /** @var array<string,mixed> */
    private array $options = [];

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        $dbRef = new \ReflectionClass(ContaiDatabase::class);
        $instanceProp = $dbRef->getProperty('instance');
        $instanceProp->setAccessible(true);
        $instanceProp->setValue(null, null);

        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->options = 'wp_options';

        $this->job = new ContaiSiteGenerationJob();

        $repository = Mockery::mock(ContaiJobRepository::class);
        $ref = new \ReflectionClass($this->job);
        $prop = $ref->getProperty('jobRepository');
        $prop->setAccessible(true);
        $prop->setValue($this->job, $repository);

        // In-memory option store so the assertions are about recorded state,
        // not about whether a mock happened to be called.
        $this->options = [];
        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = false) {
                return $this->options[$key] ?? $default;
            },
        ]);
        WP_Mock::userFunction('update_option', [
            'return' => function ($key, $value) {
                $this->options[$key] = $value;
                return true;
            },
        ]);
    }

    public function tearDown(): void
    {
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function runOptionalStep(string $name, callable $step): void
    {
        $ref = new \ReflectionClass($this->job);
        $method = $ref->getMethod('runOptionalStep');
        $method->setAccessible(true);
        $method->invoke($this->job, $name, $step);
    }

    /** @return array<int,array<string,mixed>> */
    private function warnings(): array
    {
        return $this->options[ContaiSiteGenerationJob::OPTION_STEP_WARNINGS] ?? [];
    }

    public function test_contains_an_exception_and_records_it_durably(): void
    {
        $this->runOptionalStep('setupNavigation', static function () {
            throw new \RuntimeException('menu build blew up');
        });

        $warnings = $this->warnings();

        $this->assertCount(1, $warnings, 'The failure must be recorded, not only debug-logged');
        $this->assertSame('setupNavigation', $warnings[0]['step']);
        $this->assertStringContainsString('menu build blew up', $warnings[0]['message']);
        $this->assertNotEmpty($warnings[0]['timestamp']);
    }

    /**
     * The discriminating case. Under the old catch(Exception) this Error was
     * NOT caught: it propagated out of the optional step and handle() rethrew
     * it, taking down a site generation that was otherwise complete.
     */
    public function test_contains_a_php_error_not_just_an_exception(): void
    {
        $this->runOptionalStep('generateComments', static function () {
            throw new \TypeError('argument of the wrong shape');
        });

        $warnings = $this->warnings();

        $this->assertCount(1, $warnings, 'A PHP Error must be contained too — "optional" has to mean optional');
        $this->assertSame('generateComments', $warnings[0]['step']);
        $this->assertStringContainsString('argument of the wrong shape', $warnings[0]['message']);
    }

    public function test_a_successful_step_records_nothing(): void
    {
        $ran = false;
        $this->runOptionalStep('setupAdsManager', static function () use (&$ran) {
            $ran = true;
        });

        $this->assertTrue($ran, 'The step body must actually run');
        $this->assertSame([], $this->warnings(), 'A step that succeeds must not leave a warning');
    }

    public function test_records_each_failing_step_separately(): void
    {
        $this->runOptionalStep('generateComments', static function () {
            throw new \RuntimeException('first');
        });
        $this->runOptionalStep('setupNavigation', static function () {
            throw new \RuntimeException('second');
        });

        $warnings = $this->warnings();

        $this->assertCount(2, $warnings);
        $this->assertSame(['generateComments', 'setupNavigation'], array_column($warnings, 'step'));
    }

    /**
     * Bounded FIFO, mirroring ContaiClientLogReporter's buffer. Re-executions
     * must not grow the option without limit.
     */
    public function test_warning_buffer_is_bounded_and_keeps_the_newest(): void
    {
        $total = ContaiSiteGenerationJob::MAX_STEP_WARNINGS + 5;

        for ($i = 0; $i < $total; $i++) {
            $this->runOptionalStep('setupNavigation', static function () use ($i) {
                throw new \RuntimeException("failure {$i}");
            });
        }

        $warnings = $this->warnings();

        $this->assertCount(ContaiSiteGenerationJob::MAX_STEP_WARNINGS, $warnings);
        $this->assertStringContainsString(
            'failure ' . ($total - 1),
            end($warnings)['message'],
            'The most recent failure must survive the trim'
        );
        $this->assertStringContainsString(
            'failure 5',
            $warnings[0]['message'],
            'The oldest entries are the ones dropped'
        );
    }

    /**
     * Long messages are truncated so one exploded stack message cannot bloat
     * the option.
     */
    public function test_truncates_a_very_long_message(): void
    {
        $this->runOptionalStep('setupSearchConsole', static function () {
            throw new \RuntimeException(str_repeat('x', 2000));
        });

        $this->assertLessThanOrEqual(500, strlen($this->warnings()[0]['message']));
    }

    /**
     * Wiring guard: all four optional steps must route through the shared
     * helper. Declared as a source assertion rather than dressed up as
     * behavioural — executeStep() constructs its collaborators internally, so
     * driving each case end to end would assert more about the mocks than
     * about the wiring.
     */
    public function test_all_four_optional_steps_route_through_the_guard(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/includes/services/jobs/SiteGenerationJob.php');

        foreach (['generateComments', 'setupSearchConsole', 'setupAdsManager', 'setupNavigation'] as $step) {
            $this->assertStringContainsString(
                "\$this->runOptionalStep('{$step}'",
                $source,
                "Optional step {$step} must be contained by the shared guard (#48)"
            );
        }

        $this->assertStringNotContainsString(
            'contai_log("Optional step',
            $source,
            'Optional-step failures must not go back to the WP_DEBUG-gated log (#48)'
        );
    }
}
