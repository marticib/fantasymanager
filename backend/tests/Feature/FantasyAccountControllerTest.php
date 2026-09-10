<?php

namespace Tests\Feature;

use App\Models\FantasyAccount;
use App\Models\FantasyLeague;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FantasyAccountControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The "Desconnectar LaLiga Fantasy" button (Settings) calls this —
     * previously the endpoint existed but nothing in the app reached it.
     * It must clear only the LaLiga session, never the data already
     * synced under this account (leagues, players, history, ...).
     */
    public function test_destroy_clears_only_the_laliga_session_not_synced_data(): void
    {
        $user = User::factory()->create();
        $account = FantasyAccount::create([
            'user_id' => $user->id,
            'access_token' => 'a-real-looking-token',
            'refresh_token' => 'a-real-looking-refresh-token',
            'token_client_id' => 'some-client-id',
            'token_expires_at' => Carbon::now()->addDay(),
            'nickname' => 'F.C. Seracat',
        ]);
        $league = FantasyLeague::create(['fantasy_account_id' => $account->id, 'external_id' => uniqid(), 'name' => 'Keep Me']);
        $account->forceFill(['active_league_id' => $league->id])->save();

        $response = $this->actingAs($user, 'sanctum')->deleteJson('/api/fantasy-account');

        $response->assertOk();

        $fresh = $account->fresh();
        $this->assertNull($fresh->access_token);
        $this->assertNull($fresh->refresh_token);
        $this->assertNull($fresh->token_client_id);
        $this->assertNull($fresh->token_expires_at);
        $this->assertFalse($fresh->hasValidTokens());

        // Untouched: the point is to force a re-login, not to wipe synced data.
        $this->assertSame('F.C. Seracat', $fresh->nickname);
        $this->assertSame($league->id, $fresh->active_league_id);
        $this->assertDatabaseHas('fantasy_leagues', ['id' => $league->id]);
    }

    /** GET /fantasy-account's hasTokens flips to false right after disconnecting, so the UI reacts immediately. */
    public function test_show_reflects_disconnection_immediately(): void
    {
        $user = User::factory()->create();
        FantasyAccount::create([
            'user_id' => $user->id,
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => Carbon::now()->addDay(),
        ]);

        $this->actingAs($user, 'sanctum')->deleteJson('/api/fantasy-account')->assertOk();
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/fantasy-account');

        $response->assertOk();
        $this->assertFalse($response->json('hasTokens'));
    }
}
