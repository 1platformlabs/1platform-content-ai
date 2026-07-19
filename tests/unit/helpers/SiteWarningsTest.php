<?php

namespace ContAI\Tests\Unit\Helpers;

use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * The durable warning store, shared by the job and the nav-menu resolvers (#48).
 *
 * v2.38.12 gave failing optional steps somewhere to be seen, because
 * contai_log() writes nothing without WP_DEBUG and production runs with it off.
 * The nav-menu location resolvers need the same treatment for the same reason:
 * a misconfiguration the wizard applied left no trace anywhere, which is why
 * every root cause on this issue had to be found by reading code.
 */
class SiteWarningsTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        require_once dirname(__DIR__, 3) . '/includes/helpers/site-warnings.php';
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    /**
     * @return array<int, array<string, mixed>> Whatever was stored.
     */
    private function recordAndCapture(array $existing, string $step, string $message, ?string $label = null): array
    {
        $stored = [];

        WP_Mock::userFunction('get_option', [
            'return' => function ($name, $default = false) use ($existing) {
                return $name === CONTAI_SITE_WARNINGS_OPTION ? $existing : $default;
            },
        ]);
        WP_Mock::userFunction('update_option', [
            'return' => function ($name, $value) use (&$stored) {
                if ($name === CONTAI_SITE_WARNINGS_OPTION) {
                    $stored = $value;
                }
                return true;
            },
        ]);

        contai_record_site_warning($step, $message, $label);

        return $stored;
    }

    public function test_records_the_step_verbatim(): void
    {
        $stored = $this->recordAndCapture([], 'footer nav location', 'no footer location found');

        $this->assertCount(1, $stored);
        $this->assertSame('footer nav location', $stored[0]['step']);
        $this->assertStringContainsString('no footer location found', $stored[0]['message']);
        $this->assertNotEmpty($stored[0]['timestamp']);
    }

    /**
     * The log label is presentation only. If it leaked into the stored record,
     * ContaiSiteGenerationJob's warnings would stop being keyed by bare step
     * name and OptionalStepFailureTest's assertions would be reading a
     * sentence instead of 'setupNavigation'.
     */
    public function test_log_label_does_not_leak_into_the_stored_step(): void
    {
        $stored = $this->recordAndCapture(
            [],
            'setupNavigation',
            'menu build blew up',
            "optional site-generation step 'setupNavigation' failed"
        );

        $this->assertSame('setupNavigation', $stored[0]['step']);
    }

    public function test_appends_rather_than_replacing(): void
    {
        $existing = [['step' => 'earlier', 'message' => 'x', 'timestamp' => '2026-01-01T00:00:00+00:00']];

        $stored = $this->recordAndCapture($existing, 'later', 'y');

        $this->assertCount(2, $stored);
        $this->assertSame('earlier', $stored[0]['step']);
        $this->assertSame('later', $stored[1]['step']);
    }

    /**
     * get_option() returns false when the option has never been written, and a
     * hand-edited option can be any shape at all. Neither may fatal the wizard.
     */
    public function test_survives_an_option_that_is_not_a_list(): void
    {
        $stored = $this->recordAndCapture(['not-a-list' => true], 'ctx', 'msg');

        $this->assertNotEmpty($stored, 'A malformed option must not fatal the wizard');

        $last = end($stored);
        $this->assertSame('ctx', $last['step'], 'The new warning must still be appended');
    }

    public function test_survives_an_unwritten_option(): void
    {
        $stored = $this->recordAndCapture([], 'ctx', 'msg');

        $this->assertCount(1, $stored);
        $this->assertSame('ctx', $stored[0]['step']);
    }

    public function test_is_bounded_fifo(): void
    {
        $existing = [];
        for ($i = 0; $i < CONTAI_SITE_WARNINGS_MAX; $i++) {
            $existing[] = ['step' => "old-$i", 'message' => 'x', 'timestamp' => '2026-01-01T00:00:00+00:00'];
        }

        $stored = $this->recordAndCapture($existing, 'newest', 'y');

        $this->assertCount(CONTAI_SITE_WARNINGS_MAX, $stored, 'The buffer must stay bounded across re-executions');
        $this->assertSame('old-1', $stored[0]['step'], 'The OLDEST entry is the one dropped');
        $this->assertSame('newest', $stored[CONTAI_SITE_WARNINGS_MAX - 1]['step']);
    }

    public function test_message_is_truncated(): void
    {
        $stored = $this->recordAndCapture([], 'ctx', str_repeat('a', 900));
        $this->assertSame(500, strlen($stored[0]['message']));
    }

    /**
     * ContaiSiteGenerationJob keeps its own class constants as public API for
     * the tests that already read them. They must name the SAME store the
     * helpers write to, or warnings would split across two options and the
     * documented `wp option get` would show only half of them.
     */
    public function test_job_constants_agree_with_the_shared_store(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/services/jobs/SiteGenerationJob.php';

        $this->assertSame(
            CONTAI_SITE_WARNINGS_OPTION,
            \ContaiSiteGenerationJob::OPTION_STEP_WARNINGS,
            'Both must name one option, or `wp option get` shows a partial picture'
        );
        $this->assertSame(
            CONTAI_SITE_WARNINGS_MAX,
            \ContaiSiteGenerationJob::MAX_STEP_WARNINGS
        );
    }
}
