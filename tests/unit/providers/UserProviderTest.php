<?php

namespace ContAI\Tests\Unit\Providers;

use WP_Mock;
use PHPUnit\Framework\TestCase;
use UserProvider;

class UserProviderTest extends TestCase {

    private UserProvider $provider;

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        $this->provider = new UserProvider();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_get_user_profile_returns_array(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_user_profile', null)
            ->andReturn(['userId' => 'user-123', 'name' => 'John']);

        $profile = $this->provider->getUserProfile();

        $this->assertIsArray($profile);
        $this->assertSame('user-123', $profile['userId']);
        $this->assertSame('John', $profile['name']);
    }

    public function test_get_user_profile_returns_null_when_not_set(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_user_profile', null)
            ->andReturn(null);

        $this->assertNull($this->provider->getUserProfile());
    }

    public function test_get_user_profile_returns_null_for_false(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_user_profile', null)
            ->andReturn(false);

        $this->assertNull($this->provider->getUserProfile());
    }

    public function test_get_user_profile_decodes_json_string(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_user_profile', null)
            ->andReturn('{"userId":"user-456","name":"Jane"}');

        $profile = $this->provider->getUserProfile();

        $this->assertIsArray($profile);
        $this->assertSame('user-456', $profile['userId']);
    }

    public function test_get_user_id_returns_id_from_profile(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_user_profile', null)
            ->andReturn(['userId' => 'user-789']);

        $this->assertSame('user-789', $this->provider->getUserId());
    }

    public function test_get_user_id_returns_null_when_no_profile(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_user_profile', null)
            ->andReturn(null);

        $this->assertNull($this->provider->getUserId());
    }

    public function test_get_user_id_returns_null_when_missing_key(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_user_profile', null)
            ->andReturn(['name' => 'John']);

        $this->assertNull($this->provider->getUserId());
    }
}
