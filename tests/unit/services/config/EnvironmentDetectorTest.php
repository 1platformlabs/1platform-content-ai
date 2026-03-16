<?php

namespace ContAI\Tests\Unit\Services\Config;

use WP_Mock;
use PHPUnit\Framework\TestCase;
use EnvironmentDetector;

class EnvironmentDetectorTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        EnvironmentDetector::reset();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_detect_returns_development_for_local_domain(): void {
        WP_Mock::userFunction('get_site_url')
            ->andReturn('https://wpcontentai.local');

        $env = EnvironmentDetector::detect();

        $this->assertSame('development', $env);
    }

    public function test_detect_returns_production_for_non_local_domain(): void {
        WP_Mock::userFunction('get_site_url')
            ->andReturn('https://example.com');

        $env = EnvironmentDetector::detect();

        $this->assertSame('production', $env);
    }

    public function test_detect_caches_result(): void {
        WP_Mock::userFunction('get_site_url')
            ->once()
            ->andReturn('https://example.com');

        $first = EnvironmentDetector::detect();
        $second = EnvironmentDetector::detect();

        $this->assertSame($first, $second);
    }

    public function test_reset_allows_re_detection(): void {
        WP_Mock::userFunction('get_site_url')
            ->andReturn('https://example.com');

        $firstEnv = EnvironmentDetector::detect();
        $this->assertSame('production', $firstEnv);

        EnvironmentDetector::reset();

        $secondEnv = EnvironmentDetector::detect();
        $this->assertSame($firstEnv, $secondEnv);
    }

    public function test_is_development_returns_true_for_local(): void {
        WP_Mock::userFunction('get_site_url')
            ->andReturn('https://site.local');

        $this->assertTrue(EnvironmentDetector::isDevelopment());
    }

    public function test_is_development_returns_false_for_production(): void {
        WP_Mock::userFunction('get_site_url')
            ->andReturn('https://example.com');

        $this->assertFalse(EnvironmentDetector::isDevelopment());
    }

    public function test_is_production_returns_true_for_live_site(): void {
        WP_Mock::userFunction('get_site_url')
            ->andReturn('https://mysite.com');

        $this->assertTrue(EnvironmentDetector::isProduction());
    }

    public function test_is_production_returns_false_for_local(): void {
        WP_Mock::userFunction('get_site_url')
            ->andReturn('https://site.local');

        $this->assertFalse(EnvironmentDetector::isProduction());
    }

    public function test_detect_uses_wp_siteurl_constant_when_defined(): void {
        if (!defined('WP_SITEURL')) {
            define('WP_SITEURL', 'https://constant.local');
        }

        EnvironmentDetector::reset();
        $env = EnvironmentDetector::detect();

        $this->assertSame('development', $env);
    }
}
