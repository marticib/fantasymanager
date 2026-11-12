<?php

namespace Tests\Feature;

use App\Models\FantasyAccount;
use App\Models\FantasyAlert;
use App\Models\FantasyLeague;
use App\Models\FantasyMarketPlayer;
use App\Models\FantasyPlayer;
use App\Models\FantasyStanding;
use App\Models\FantasyTeam;
use App\Models\FantasyTeamPlayer;
use App\Models\FantasyTransaction;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notifications\ReminderService;
use App\Services\Recommendation\TradeLedgerService;
use App\Services\Sync\LeagueActivitySync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ActivityAndNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FantasyAccount $account;

    private FantasyLeague $league;

    private FantasyTeam $mine;

    private FantasyTeam $rival;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-12 12:00:00'));

        $this->user = User::factory()->create();
        $account = FantasyAccount::create(['user_id' => $this->user->id, 'access_token' => 't', 'refresh_token' => 'r', 'token_expires_at' => now()->addDay(), 'fantasy_user_id' => '100']);
        $this->league = FantasyLeague::create(['fantasy_account_id' => $account->id, 'external_id' => 'L1', 'name' => 'Lliga']);
        $this->mine = FantasyTeam::create(['fantasy_league_id' => $this->league->id, 'external_id' => 'T1', 'name' => 'Meu', 'is_mine' => true]);
        $this->rival = FantasyTeam::create(['fantasy_league_id' => $this->league->id, 'external_id' => 'T2', 'name' => 'Rival', 'is_mine' => false]);
        $account->forceFill(['active_league_id' => $this->league->id, 'active_team_id' => $this->mine->id])->save();
        $this->account = $account->fresh();
        FantasyStanding::create(['fantasy_league_id' => $this->league->id, 'fantasy_team_id' => $this->rival->id, 'position' => 1, 'points' => 10, 'captured_at' => now(),
            'raw_payload' => ['team' => ['managerId' => 200]]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function player(string $externalId, string $name, int $value = 5_000_000): FantasyPlayer
    {
        return FantasyPlayer::create(['external_id' => $externalId, 'name' => $name, 'position' => 'MF', 'market_value' => $value]);
    }

    private function tx(string $type, int $amount, ?FantasyPlayer $p, ?FantasyTeam $from, ?FantasyTeam $to, string $at): FantasyTransaction
    {
        return FantasyTransaction::create(['fantasy_account_id' => $this->account->id, 'fantasy_league_id' => $this->league->id, 'fantasy_player_id' => $p?->id,
            'type' => $type, 'amount' => $amount, 'from_team_id' => $from?->id, 'to_team_id' => $to?->id, 'occurred_at' => Carbon::parse($at), 'external_id' => uniqid()]);
    }

    public function test_the_activity_feed_is_stored_with_teams_resolved_and_a_lost_player_is_notified_once(): void
    {
        $p1 = $this->player('11', 'Comprat');
        $p2 = $this->player('22', 'Perdut');
        Http::fake(['*/activity/0*' => Http::response([
            ['activityTypeId' => 31, 'id' => 'a1', 'user1Id' => 100, 'playerMasterId' => 11, 'amount' => 4_000_000, 'createdAt' => '2026-11-12T10:02:00+01:00'],
            ['activityTypeId' => 1, 'id' => 'a2', 'user1Id' => 200, 'user2Id' => 100, 'playerMasterId' => 22, 'amount' => 9_000_000, 'createdAt' => '2026-11-12T09:00:00+01:00'],
            ['activityTypeId' => 6, 'id' => 'a3', 'user1Id' => 100, 'amount' => 3_000_000, 'weekNumber' => 12, 'createdAt' => '2026-11-10T04:00:00+01:00'],
            ['activityTypeId' => 4, 'id' => 'a4', 'user1Id' => 200, 'playerMasterId' => 11, 'createdAt' => '2026-11-09T04:00:00+01:00'],
        ]), '*/activity/*' => Http::response([]), '*' => Http::response([])]);

        $sync = app(LeagueActivitySync::class);
        $this->assertSame(3, $sync->sync($this->account));
        $this->assertSame(0, $sync->sync($this->account));

        $buy = FantasyTransaction::where('external_id', 'a1')->first();
        $this->assertSame([FantasyTransaction::TYPE_MARKET_BUY, null, $this->mine->id, $p1->id], [$buy->type, $buy->from_team_id, $buy->to_team_id, $buy->fantasy_player_id]);
        $lost = FantasyTransaction::where('external_id', 'a2')->first();
        $this->assertSame([$this->mine->id, $this->rival->id], [$lost->from_team_id, $lost->to_team_id]);
        $this->assertSame('200', $this->rival->fresh()->manager_external_id);

        $alerts = FantasyAlert::where('type', FantasyAlert::TYPE_PLAYER_STOLEN)->get();
        $this->assertCount(1, $alerts);
        $this->assertSame('Perdut ara és de Rival', $alerts[0]->title);
    }

    public function test_the_trade_ledger_matches_each_sale_with_its_last_purchase_and_counts_rewards(): void
    {
        $flip = $this->player('1', 'Flip');
        $start = $this->player('2', 'Inicial');
        $this->tx(FantasyTransaction::TYPE_MARKET_BUY, 4_000_000, $flip, null, $this->mine, '2026-11-01 10:00');
        $this->tx(FantasyTransaction::TYPE_TRANSFER, 5_500_000, $flip, $this->mine, $this->rival, '2026-11-05 10:00');
        $this->tx(FantasyTransaction::TYPE_MARKET_SELL, 2_000_000, $start, $this->mine, null, '2026-11-06 10:00');
        $this->tx(FantasyTransaction::TYPE_MATCHDAY_REWARD, 3_000_000, null, null, $this->mine, '2026-11-09 04:00');
        $this->tx(FantasyTransaction::TYPE_MARKET_BUY, 1_000_000, $start, null, $this->rival, '2026-11-07 10:00'); // someone else's

        $ledger = app(TradeLedgerService::class)->build($this->account);

        $this->assertSame(2, $ledger['summary']['operations']);
        $this->assertSame(1_500_000, $ledger['summary']['realizedProfit']);
        $this->assertSame(1, $ledger['summary']['unknownCost']);
        $this->assertSame(3_000_000, $ledger['summary']['rewards']);
        $this->assertSame('Flip', $ledger['summary']['best']['player']['name']);
        $this->assertSame('Rival', $ledger['closed'][1]['soldTo']);
        $this->assertSame(4, $ledger['closed'][1]['daysHeld']);
        $this->assertNull($ledger['closed'][0]['profit']);

        $this->actingAs($this->user, 'sanctum')->getJson('/api/history/trades')->assertOk()->assertJsonPath('summary.realizedProfit', 1_500_000);
    }

    public function test_the_activity_endpoint_lists_moves_and_per_team_totals(): void
    {
        $p = $this->player('1', 'Moviment');
        $this->tx(FantasyTransaction::TYPE_TRANSFER, 8_000_000, $p, $this->mine, $this->rival, '2026-11-11 10:00');
        $this->tx(FantasyTransaction::TYPE_MARKET_BUY, 1_000_000, $p, null, $this->rival, '2026-10-01 10:00'); // outside 7 days

        $res = $this->actingAs($this->user, 'sanctum')->getJson('/api/activity?days=7')->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Rival', $res->json('data.0.to.name'));
        $rival = collect($res->json('teams'))->firstWhere('id', $this->rival->id);
        $this->assertSame([8_000_000, 1, 1], [$rival['spent'], $rival['signings'], $rival['fromRivals']]);
    }

    public function test_a_cheap_clause_is_notified_once_a_day_and_a_protected_one_never(): void
    {
        $cheap = $this->player('1', 'Barat', 10_000_000);
        $locked = $this->player('2', 'Blindat', 10_000_000);
        FantasyTeamPlayer::create(['fantasy_team_id' => $this->mine->id, 'fantasy_player_id' => $cheap->id, 'clause_value' => 10_500_000]);
        FantasyTeamPlayer::create(['fantasy_team_id' => $this->mine->id, 'fantasy_player_id' => $locked->id, 'clause_value' => 10_100_000, 'clause_locked_until' => now()->addDays(3)]);

        $reminders = app(ReminderService::class);
        $this->assertTrue($reminders->clauseExposure($this->account));
        $this->assertFalse($reminders->clauseExposure($this->account));

        $alert = FantasyAlert::where('type', FantasyAlert::TYPE_CLAUSE_EXPOSED)->sole();
        $this->assertSame('Barat es pot prendre barat', $alert->title);
        $this->assertStringNotContainsString('Blindat', $alert->message);
    }

    public function test_the_market_closing_reminder_fires_within_the_last_hour_only(): void
    {
        $bidOn = $this->player('1', 'Pujat');
        FantasyMarketPlayer::create(['fantasy_league_id' => $this->league->id, 'fantasy_player_id' => $bidOn->id, 'is_on_market' => true, 'market_value' => 1,
            'expires_at' => now()->addMinutes(150), 'raw_payload' => ['bid' => ['id' => 'B1', 'money' => 1]]]);

        $reminders = app(ReminderService::class);
        $this->assertFalse($reminders->marketClosing($this->account));

        Carbon::setTestNow(now()->addMinutes(100));
        $this->assertTrue($reminders->marketClosing($this->account));
        $this->assertFalse($reminders->marketClosing($this->account));
        $this->assertStringContainsString('Pujat', FantasyAlert::where('type', FantasyAlert::TYPE_MARKET_CLOSING)->sole()->message);
    }

    public function test_the_weekly_summary_only_goes_out_on_sunday_evening(): void
    {
        $this->tx(FantasyTransaction::TYPE_MATCHDAY_REWARD, 3_000_000, null, null, $this->mine, '2026-11-10 04:00');
        $reminders = app(ReminderService::class);

        $this->assertFalse($reminders->weeklySummary($this->account)); // Thursday

        Carbon::setTestNow(Carbon::parse('2026-11-15 20:30', 'Europe/Madrid'));
        $this->assertTrue($reminders->weeklySummary($this->account));
        $this->assertFalse($reminders->weeklySummary($this->account));
        $this->assertStringContainsString('Premis de jornada: 3.000.000 €', FantasyAlert::where('type', FantasyAlert::TYPE_WEEKLY_SUMMARY)->sole()->message);
    }

    public function test_a_new_notification_is_forwarded_to_telegram_when_connected(): void
    {
        config(['services.telegram.bot_token' => 'TOKEN', 'services.telegram.bot_username' => 'fantasy_bot', 'services.frontend_url' => 'https://app.test']);
        $this->account->forceFill(['telegram_chat_id' => '555'])->save();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        app(\App\Services\Notifications\AlertService::class)->notify($this->account, 'X', 'Títol', 'Missatge', ['link' => '/sales']);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'botTOKEN/sendMessage') && $r['chat_id'] === '555'
            && str_contains($r['text'], '<b>Títol</b>') && str_contains($r['text'], 'https://app.test/sales'));
    }

    public function test_telegram_linking_finds_the_start_message_with_the_code(): void
    {
        config(['services.telegram.bot_token' => 'TOKEN', 'services.telegram.bot_username' => 'fantasy_bot']);

        $url = $this->actingAs($this->user, 'sanctum')->postJson('/api/notifications/telegram/link')->assertOk()->json('url');
        $code = substr($url, strrpos($url, '=') + 1);
        $this->assertStringStartsWith('https://t.me/fantasy_bot?start=', $url);

        Http::fake([
            '*/getUpdates*' => Http::response(['ok' => true, 'result' => [['message' => ['text' => '/start '.$code, 'chat' => ['id' => 777]]]]]),
            '*/sendMessage' => Http::response(['ok' => true]),
        ]);

        $this->actingAs($this->user, 'sanctum')->postJson('/api/notifications/telegram/verify')->assertOk()->assertJson(['connected' => true]);
        $this->assertSame('777', $this->account->fresh()->telegram_chat_id);
    }

    public function test_push_subscriptions_are_stored_per_device_and_removed(): void
    {
        config(['services.webpush.public_key' => 'PUB', 'services.webpush.private_key' => 'PRIV']);
        $payload = ['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'k', 'auth' => 'a']];

        $this->actingAs($this->user, 'sanctum')->postJson('/api/notifications/push', $payload)->assertOk();
        $this->actingAs($this->user, 'sanctum')->postJson('/api/notifications/push', $payload)->assertOk();
        $this->assertSame(1, PushSubscription::count());
        $this->actingAs($this->user, 'sanctum')->getJson('/api/notifications/channels')->assertJsonPath('push.devices', 1)->assertJsonPath('push.publicKey', 'PUB');

        $this->actingAs($this->user, 'sanctum')->deleteJson('/api/notifications/push', ['endpoint' => 'https://push.example/abc'])->assertOk();
        $this->assertSame(0, PushSubscription::count());
    }
}
