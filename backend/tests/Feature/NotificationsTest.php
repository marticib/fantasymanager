<?php

namespace Tests\Feature;

use App\Models\FantasyAccount;
use App\Models\FantasyAlert;
use App\Models\FantasyLeague;
use App\Models\FantasyMarketPlayer;
use App\Models\FantasyPlayer;
use App\Models\FantasyTeam;
use App\Models\User;
use App\Services\Sync\FantasySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: FantasyAccount, 2: FantasyLeague, 3: FantasyTeam} */
    private function fixture(array $tokens = ['access_token' => 'token', 'refresh_token' => 'refresh']): array
    {
        $user = User::factory()->create();
        $account = FantasyAccount::create(array_merge(['user_id' => $user->id, 'token_expires_at' => now()->addHours(12)], $tokens));
        $league = FantasyLeague::create(['fantasy_account_id' => $account->id, 'external_id' => 'L1', 'name' => 'Lliga']);
        $team = FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => 'T1', 'name' => 'Mine', 'is_mine' => true]);
        $account->forceFill(['active_league_id' => $league->id, 'active_team_id' => $team->id])->save();

        return [$user, $account->fresh(), $league, $team];
    }

    private function ownListing(string $marketId, string $playerId, string $name, int $offers): array
    {
        return [
            'id' => $marketId,
            'playerMaster' => ['id' => $playerId, 'name' => $name, 'positionId' => 3, 'marketValue' => 5_000_000, 'points' => 10, 'averagePoints' => 2.0, 'playerStatus' => 'ok'],
            'salePrice' => 5_000_000,
            'numberOfOffers' => $offers,
            'sellerTeam' => ['id' => 'T1'],
        ];
    }

    public function test_a_new_offer_on_my_listing_raises_one_notification_and_a_repeat_sync_does_not_duplicate_it(): void
    {
        [, $account] = $this->fixture();

        Http::fake([
            '*/league/*/market*' => Http::sequence()
                ->push([$this->ownListing('M1', '10', 'Pere Venut', 0)])
                ->push([$this->ownListing('M1', '10', 'Pere Venut', 1)])
                ->push([$this->ownListing('M1', '10', 'Pere Venut', 1)]),
            '*' => Http::response(['data' => []], 200),
        ]);

        $sync = app(FantasySyncService::class);
        $sync->syncMarket($account);
        $this->assertSame(0, FantasyAlert::count());

        $sync->syncMarket($account);
        $sync->syncMarket($account);

        $alerts = FantasyAlert::where('type', FantasyAlert::TYPE_OFFER_RECEIVED)->get();
        $this->assertCount(1, $alerts);
        $this->assertSame('Nova oferta per Pere Venut', $alerts->first()->title);
        $this->assertSame('/sales', $alerts->first()->link);
    }

    public function test_an_auction_i_had_bid_on_leaving_the_market_raises_an_auction_ended_notification(): void
    {
        [, $account, $league] = $this->fixture();
        $player = FantasyPlayer::create(['external_id' => '20', 'name' => 'Joan Subhasta', 'position' => 'FW', 'market_value' => 3_000_000]);
        FantasyMarketPlayer::create([
            'fantasy_league_id' => $league->id,
            'fantasy_player_id' => $player->id,
            'market_value' => 3_000_000,
            'is_on_market' => true,
            'raw_payload' => ['id' => 'M9', 'bid' => ['id' => 'B1', 'money' => 3_200_000, 'status' => 'pending']],
        ]);

        Http::fake(['*/league/*/market*' => Http::response([], 200), '*' => Http::response(['data' => []], 200)]);

        app(FantasySyncService::class)->syncMarket($account);

        $alert = FantasyAlert::where('type', FantasyAlert::TYPE_AUCTION_ENDED)->first();
        $this->assertNotNull($alert);
        $this->assertSame('Ha acabat la subhasta de Joan Subhasta', $alert->title);
        $this->assertStringContainsString('3.200.000 €', $alert->message);
    }

    public function test_an_expired_laliga_session_is_reported_as_409_with_a_code_never_as_401(): void
    {
        [$user, $account, $league] = $this->fixture();
        $account->forceFill(['token_expires_at' => now()->subMinute()])->save();
        $player = FantasyPlayer::create(['external_id' => '30', 'name' => 'X', 'position' => 'MF']);
        $listing = FantasyMarketPlayer::create(['fantasy_league_id' => $league->id, 'fantasy_player_id' => $player->id, 'is_on_market' => true, 'raw_payload' => ['id' => 'M1']]);

        Http::fake([
            'login.laliga.es/*' => Http::response(['error' => 'invalid_grant'], 400),
            '*' => Http::response([], 401),
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/market/{$listing->id}/bid", ['amount' => 1000]);

        $response->assertStatus(409)->assertJson(['code' => 'LALIGA_SESSION_EXPIRED']);
        $this->assertNotNull($account->fresh()->laliga_session_expired_at);
        $this->assertSame(1, FantasyAlert::where('type', FantasyAlert::TYPE_SESSION_EXPIRED)->count());

        $this->actingAs($user, 'sanctum')->getJson('/api/dashboard/header')
            ->assertOk()
            ->assertJsonPath('laligaSessionExpired', true);
    }

    public function test_header_reports_badges_and_unread_count_and_alerts_can_be_marked_read(): void
    {
        [$user, $account, $league, $team] = $this->fixture();
        $p1 = FantasyPlayer::create(['external_id' => '1', 'name' => 'A', 'position' => 'MF']);
        $p2 = FantasyPlayer::create(['external_id' => '2', 'name' => 'B', 'position' => 'MF']);
        FantasyMarketPlayer::create(['fantasy_league_id' => $league->id, 'fantasy_player_id' => $p1->id, 'seller_team_id' => $team->id, 'is_on_market' => true, 'raw_payload' => ['numberOfOffers' => 2]]);
        FantasyMarketPlayer::create(['fantasy_league_id' => $league->id, 'fantasy_player_id' => $p2->id, 'is_on_market' => true, 'raw_payload' => ['bid' => ['id' => 'B1', 'money' => 1]]]);
        FantasyAlert::create(['fantasy_account_id' => $account->id, 'type' => FantasyAlert::TYPE_OFFER_RECEIVED, 'title' => 't', 'message' => 'm']);

        $this->actingAs($user, 'sanctum')->getJson('/api/dashboard/header')
            ->assertOk()
            ->assertJsonPath('badges.sales', 1)
            ->assertJsonPath('badges.market', 1)
            ->assertJsonPath('badges.clauses', 0)
            ->assertJsonPath('unreadAlerts', 1)
            ->assertJsonPath('laligaSessionExpired', false)
            ->assertJsonPath('syncing', false);

        $this->actingAs($user, 'sanctum')->postJson('/api/alerts/read-all')->assertOk();
        $this->actingAs($user, 'sanctum')->getJson('/api/dashboard/header')->assertJsonPath('unreadAlerts', 0);
    }

    public function test_alerts_of_another_account_cannot_be_read_or_marked(): void
    {
        [$userA] = $this->fixture();
        [, $accountB] = $this->fixture();
        $alert = FantasyAlert::create(['fantasy_account_id' => $accountB->id, 'type' => 'X', 'title' => 'secret', 'message' => 'm']);

        $this->actingAs($userA, 'sanctum')->getJson('/api/alerts')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($userA, 'sanctum')->postJson("/api/alerts/{$alert->id}/read")->assertNotFound();
        $this->assertNull($alert->fresh()->read_at);
    }
}
