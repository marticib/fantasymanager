<?php

namespace Tests\Unit\Services\FantasyApi;

use App\Models\FantasyAccount;
use App\Models\User;
use App\Services\FantasyApi\Exceptions\FantasyApiAuthenticationException;
use App\Services\FantasyApi\Exceptions\FantasyApiException;
use App\Services\FantasyApi\Exceptions\FantasyApiRateLimitException;
use App\Services\FantasyApi\FantasyApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FantasyApiClientTest extends TestCase
{
    use RefreshDatabase;

    private function account(): FantasyAccount
    {
        return FantasyAccount::create([
            'user_id' => User::factory()->create()->id,
            'access_token' => 'valid-access-token',
            'refresh_token' => 'valid-refresh-token',
            'token_expires_at' => now()->addHours(12),
        ]);
    }

    public function test_successful_get_returns_decoded_json(): void
    {
        Http::fake([
            'fantasy-api.llt-services.com/*' => Http::response(['data' => ['id' => 1]], 200),
        ]);

        $client = $this->app->make(FantasyApiClient::class);
        $result = $client->get($this->account(), '/v1/competition/1/players');

        $this->assertSame(['data' => ['id' => 1]], $result);
    }

    public function test_401_triggers_a_single_refresh_then_retries_successfully(): void
    {
        Http::fake([
            'login.laliga.es/*' => Http::response([
                'access_token' => 'refreshed-token',
                'refresh_token' => 'refreshed-refresh-token',
                'expires_in' => 3600,
            ], 200),
            'fantasy-api.llt-services.com/*' => Http::sequence()
                ->push(['message' => 'Unauthorized'], 401)
                ->push(['data' => ['ok' => true]], 200),
        ]);

        $client = $this->app->make(FantasyApiClient::class);
        $account = $this->account();
        $result = $client->get($account, '/v1/competition/1/players');

        $this->assertSame(['data' => ['ok' => true]], $result);
        $this->assertSame('refreshed-token', $account->fresh()->access_token);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'login.laliga.es'));
    }

    public function test_401_after_refresh_still_fails_throws_authentication_exception(): void
    {
        Http::fake([
            'login.laliga.es/*' => Http::response([
                'access_token' => 'refreshed-token',
                'expires_in' => 3600,
            ], 200),
            'fantasy-api.llt-services.com/*' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $client = $this->app->make(FantasyApiClient::class);

        $this->expectException(FantasyApiAuthenticationException::class);
        $client->get($this->account(), '/v1/competition/1/players');
    }

    public function test_rate_limit_retries_then_succeeds(): void
    {
        Http::fake([
            'fantasy-api.llt-services.com/*' => Http::sequence()
                ->push(['message' => 'Too Many Requests'], 429, ['Retry-After' => '1'])
                ->push(['data' => ['ok' => true]], 200),
        ]);

        $client = $this->app->make(FantasyApiClient::class);
        $result = $client->get($this->account(), '/v1/competition/1/players');

        $this->assertSame(['data' => ['ok' => true]], $result);
    }

    public function test_rate_limit_exhausted_throws_rate_limit_exception(): void
    {
        Http::fake([
            'fantasy-api.llt-services.com/*' => Http::response(['message' => 'Too Many Requests'], 429, ['Retry-After' => '0']),
        ]);

        $client = $this->app->make(FantasyApiClient::class);

        $this->expectException(FantasyApiRateLimitException::class);
        $client->get($this->account(), '/v1/competition/1/players');
    }

    public function test_server_error_throws_fantasy_api_exception(): void
    {
        Http::fake([
            'fantasy-api.llt-services.com/*' => Http::response(['message' => 'Boom'], 500),
        ]);

        $client = $this->app->make(FantasyApiClient::class);

        $this->expectException(FantasyApiException::class);
        $client->get($this->account(), '/v1/competition/1/players');
    }

    public function test_missing_tokens_throws_authentication_exception_without_any_http_call(): void
    {
        Http::fake();

        $account = FantasyAccount::create(['user_id' => User::factory()->create()->id]);
        $client = $this->app->make(FantasyApiClient::class);

        $this->expectException(FantasyApiAuthenticationException::class);
        $client->get($account, '/v1/competition/1/players');

        Http::assertNothingSent();
    }
}
