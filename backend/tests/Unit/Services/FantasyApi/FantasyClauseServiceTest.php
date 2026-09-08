<?php

namespace Tests\Unit\Services\FantasyApi;

use App\Models\FantasyAccount;
use App\Models\User;
use App\Services\FantasyApi\FantasyClauseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FantasyClauseServiceTest extends TestCase
{
    use RefreshDatabase;

    private function account(): FantasyAccount
    {
        return FantasyAccount::create([
            'user_id' => User::factory()->create()->id,
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHours(12),
        ]);
    }

    public function test_pay_clause_posts_to_the_correct_endpoint_with_the_amount(): void
    {
        Http::fake(['fantasy-api.llt-services.com/*' => Http::response(['data' => ['ok' => true]], 200)]);

        $service = $this->app->make(FantasyClauseService::class);
        $service->payClause($this->account(), 'league-1', 'player-42', 15_000_000);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/v1/competition/1/league/league-1/buyout/player-42/pay')
                && $request['buyoutClauseToPay'] === 15_000_000
                && $request->method() === 'POST';
        });
    }

    public function test_check_shield_hits_the_correct_endpoint(): void
    {
        Http::fake(['fantasy-api.llt-services.com/*' => Http::response(['data' => ['shielded' => false]], 200)]);

        $service = $this->app->make(FantasyClauseService::class);
        $service->checkShield($this->account(), 'league-1', 'player-team-7');

        Http::assertSent(fn (Request $request) => str_contains(
            $request->url(),
            '/v1/competition/1/league/league-1/player-team/player-team-7/check-shield',
        ));
    }
}
