<?php

namespace ContAI\Tests\Unit\Services\Billing;

use Mockery;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use ContaiBillingService;
use ContaiOnePlatformClient;
use ContaiOnePlatformEndpoints;
use ContaiOnePlatformResponse;
use ContaiUserProvider;

/**
 * A top-up is the site owner buying credit for their OWN balance.
 *
 * It used to go to `POST /users/transactions`, which is the endpoint for
 * collecting from a buyer: the charge was not recorded as a top-up, and the
 * owner's saved card was not kept between top-ups. #223.
 */
class BillingServiceTopUpTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_top_up_goes_to_the_balance_top_up_endpoint(): void
    {
        $client = Mockery::mock(ContaiOnePlatformClient::class);
        $response = Mockery::mock(ContaiOnePlatformResponse::class);
        $client->shouldReceive('post')
            ->once()
            ->with('/users/balance/topups', [
                'amount' => 25.0,
                'currency' => 'USD',
                'description' => 'Account top-up',
            ])
            ->andReturn($response);

        $service = new ContaiBillingService($client, Mockery::mock(ContaiUserProvider::class));

        $this->assertSame($response, $service->createTransaction(25.0, 'USD', 'Account top-up'));
    }

    public function test_the_endpoint_constant_is_not_the_collections_one(): void
    {
        $this->assertSame('/users/balance/topups', ContaiOnePlatformEndpoints::USERS_BALANCE_TOPUPS);
        $this->assertNotSame(
            ContaiOnePlatformEndpoints::USERS_TRANSACTIONS,
            ContaiOnePlatformEndpoints::USERS_BALANCE_TOPUPS
        );
    }
}
