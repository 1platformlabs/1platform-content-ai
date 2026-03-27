<?php

namespace ContAI\Tests\Unit\Services\Setup;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiPublisuitesSetupService;
use ContaiPublisuitesService;
use ContaiOnePlatformResponse;

class PublisuitesSetupServiceTest extends TestCase
{
    private ContaiPublisuitesService $service;
    private ContaiPublisuitesSetupService $setupService;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        $this->service = Mockery::mock(ContaiPublisuitesService::class);
        $this->setupService = new ContaiPublisuitesSetupService($this->service);
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // ── Happy Path ───────────────────────────────────────────────

    public function test_activate_success_completes_three_steps(): void
    {
        $connectData = [
            'publisuites_id' => 'ps-123',
            'verification_file_name' => 'publisuites-verify-token.html',
            'verification_file_content' => '<html>verify</html>',
        ];

        $this->service
            ->shouldReceive('connectWebsite')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(true, $connectData, 'Added', 200));

        $this->service
            ->shouldReceive('savePublisuitesConfig')
            ->twice();

        $this->service
            ->shouldReceive('createVerificationFile')
            ->once()
            ->andReturn(['success' => true, 'message' => 'File created']);

        $this->service
            ->shouldReceive('verifyWebsite')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(true, ['verified' => true, 'verified_at' => '2026-03-26T10:00:00Z'], 'Verified', 200));

        $this->service
            ->shouldReceive('getPublisuitesConfig')
            ->once()
            ->andReturn(['publisuites_id' => 'ps-123', 'status' => 'pending_verification']);

        $result = $this->setupService->activatePublisuites();

        $this->assertTrue($result['success']);
        $this->assertCount(3, $result['steps']);
        $this->assertEmpty($result['errors']);
        $this->assertEquals('Website registered in marketplace', $result['steps'][0]);
        $this->assertEquals('Verification file created', $result['steps'][1]);
        $this->assertEquals('Website verified', $result['steps'][2]);
    }

    // ── Failure at Step 1 (connect) ──────────────────────────────

    public function test_activate_connect_fails_stops_flow(): void
    {
        $this->service
            ->shouldReceive('connectWebsite')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(false, null, 'API error', 502));

        $this->service->shouldNotReceive('savePublisuitesConfig');
        $this->service->shouldNotReceive('createVerificationFile');
        $this->service->shouldNotReceive('verifyWebsite');

        $result = $this->setupService->activatePublisuites();

        $this->assertFalse($result['success']);
        $this->assertEmpty($result['steps']);
        $this->assertStringContainsString('Failed to register website: API error', $result['errors'][0]);
    }

    // ── Failure at Step 2 (create file) ──────────────────────────

    public function test_activate_create_file_fails_stops_at_step2(): void
    {
        $connectData = [
            'publisuites_id' => 'ps-123',
            'verification_file_name' => 'verify.html',
            'verification_file_content' => '<html>verify</html>',
        ];

        $this->service
            ->shouldReceive('connectWebsite')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(true, $connectData, 'Added', 200));

        $this->service
            ->shouldReceive('savePublisuitesConfig')
            ->once();

        $this->service
            ->shouldReceive('createVerificationFile')
            ->once()
            ->andReturn(['success' => false, 'message' => 'Permission denied']);

        $this->service->shouldNotReceive('verifyWebsite');
        $this->service->shouldNotReceive('getPublisuitesConfig');

        $result = $this->setupService->activatePublisuites();

        $this->assertFalse($result['success']);
        $this->assertCount(1, $result['steps']);
        $this->assertEquals('Website registered in marketplace', $result['steps'][0]);
        $this->assertStringContainsString('Failed to create verification file: Permission denied', $result['errors'][0]);
    }

    // ── Failure at Step 3 (verify) ───────────────────────────────

    public function test_activate_verify_fails_stops_at_step3(): void
    {
        $connectData = [
            'publisuites_id' => 'ps-123',
            'verification_file_name' => 'verify.html',
            'verification_file_content' => '<html>verify</html>',
        ];

        $this->service
            ->shouldReceive('connectWebsite')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(true, $connectData, 'Added', 200));

        $this->service
            ->shouldReceive('savePublisuitesConfig')
            ->once();

        $this->service
            ->shouldReceive('createVerificationFile')
            ->once()
            ->andReturn(['success' => true, 'message' => 'File created']);

        $this->service
            ->shouldReceive('verifyWebsite')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(false, null, 'Verification pending', 400));

        $this->service->shouldNotReceive('getPublisuitesConfig');

        $result = $this->setupService->activatePublisuites();

        $this->assertFalse($result['success']);
        $this->assertCount(2, $result['steps']);
        $this->assertStringContainsString('Failed to verify website: Verification pending', $result['errors'][0]);
    }

    // ── Idempotent add (already_added) ───────────────────────────

    public function test_activate_idempotent_add_continues_flow(): void
    {
        $connectData = [
            'publisuites_id' => 'ps-123',
            'status' => 'already_added',
            'verification_file_name' => 'publisuites-verify-token.html',
            'verification_file_content' => '<html>verify</html>',
        ];

        $this->service
            ->shouldReceive('connectWebsite')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(true, $connectData, 'Already added', 200));

        $this->service
            ->shouldReceive('savePublisuitesConfig')
            ->twice();

        $this->service
            ->shouldReceive('createVerificationFile')
            ->once()
            ->andReturn(['success' => true, 'message' => 'File created']);

        $this->service
            ->shouldReceive('verifyWebsite')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(true, ['verified' => true, 'verified_at' => '2026-03-26T10:00:00Z'], 'Verified', 200));

        $this->service
            ->shouldReceive('getPublisuitesConfig')
            ->once()
            ->andReturn(['publisuites_id' => 'ps-123', 'status' => 'pending_verification']);

        $result = $this->setupService->activatePublisuites();

        $this->assertTrue($result['success']);
        $this->assertCount(3, $result['steps']);
        $this->assertEmpty($result['errors']);
    }

    // ── Config saved with correct data after connect ─────────────

    public function test_activate_saves_config_with_correct_data_after_connect(): void
    {
        $connectData = [
            'publisuites_id' => 'ps-456',
            'verification_file_name' => 'publisuites-verify-abc.html',
            'verification_file_content' => '<html>abc-verify</html>',
        ];

        $this->service
            ->shouldReceive('connectWebsite')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(true, $connectData, 'Added', 200));

        $this->service
            ->shouldReceive('savePublisuitesConfig')
            ->once()
            ->with(Mockery::on(function ($config) {
                return $config['publisuites_id'] === 'ps-456'
                    && $config['verification_file_name'] === 'publisuites-verify-abc.html'
                    && $config['verification_file_content'] === '<html>abc-verify</html>'
                    && $config['status'] === 'pending_verification'
                    && $config['verified'] === false;
            }))
            ->ordered();

        // Allow second savePublisuitesConfig call (post-verify)
        $this->service->shouldReceive('savePublisuitesConfig')->once()->ordered();

        $this->service
            ->shouldReceive('createVerificationFile')
            ->once()
            ->andReturn(['success' => true, 'message' => 'File created']);

        $this->service
            ->shouldReceive('verifyWebsite')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(true, ['verified' => true, 'verified_at' => '2026-03-26'], 'Verified', 200));

        $this->service
            ->shouldReceive('getPublisuitesConfig')
            ->once()
            ->andReturn(['publisuites_id' => 'ps-456']);

        $result = $this->setupService->activatePublisuites();

        $this->assertTrue($result['success']);
    }

    // ── Config updated to active after verify ────────────────────

    public function test_activate_updates_config_to_active_after_verify(): void
    {
        $connectData = [
            'publisuites_id' => 'ps-789',
            'verification_file_name' => 'verify.html',
            'verification_file_content' => '<html>verify</html>',
        ];

        $this->service
            ->shouldReceive('connectWebsite')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(true, $connectData, 'Added', 200));

        // First save: post-connect (pending)
        $this->service
            ->shouldReceive('savePublisuitesConfig')
            ->once()
            ->with(Mockery::on(function ($config) {
                return $config['status'] === 'pending_verification' && $config['verified'] === false;
            }))
            ->ordered();

        $this->service
            ->shouldReceive('createVerificationFile')
            ->once()
            ->andReturn(['success' => true, 'message' => 'File created']);

        $this->service
            ->shouldReceive('verifyWebsite')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(true, ['verified' => true, 'verified_at' => '2026-03-26T12:00:00Z'], 'Verified', 200));

        $this->service
            ->shouldReceive('getPublisuitesConfig')
            ->once()
            ->andReturn(['publisuites_id' => 'ps-789', 'status' => 'pending_verification']);

        // Second save: post-verify (active)
        $this->service
            ->shouldReceive('savePublisuitesConfig')
            ->once()
            ->with(Mockery::on(function ($config) {
                return $config['verified'] === true
                    && $config['status'] === 'active'
                    && $config['verifiedAt'] === '2026-03-26T12:00:00Z';
            }))
            ->ordered();

        $result = $this->setupService->activatePublisuites();

        $this->assertTrue($result['success']);
        $this->assertCount(3, $result['steps']);
    }
}
