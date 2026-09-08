<?php

namespace Tests\Unit\Services\FantasyApi;

use App\Models\FantasyAccount;
use App\Models\User;
use App\Services\FantasyApi\FantasyApiClient;
use App\Services\FantasyApi\FantasyTeamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression coverage for the real /teams/{id}/lineup shape (confirmed live,
 * not guessed): the roster sits under formation.{goalkeeper,defender,
 * midfield,striker,bench}[], each slot nesting the player under
 * `playerMaster` with `buyoutClause` as a SIBLING field, not inside it. The
 * top-level `team` key is unrelated metadata (id/teamValue/teamPoints) that
 * must NOT be mistaken for the roster.
 */
class FantasyTeamServiceTest extends TestCase
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

    private function playerSlot(string $id, string $name, int $buyoutClause): array
    {
        return [
            'playerMaster' => [
                'id' => $id,
                'name' => $name,
                'positionId' => 1,
                'marketValue' => 5_000_000,
                'points' => 40,
                'averagePoints' => 4.0,
                'playerStatus' => 'ok',
            ],
            'buyoutClause' => $buyoutClause,
            'playerTeamId' => 'pt-'.$id,
        ];
    }

    public function test_extracts_starters_and_bench_with_clause_values_from_the_real_shape(): void
    {
        Http::fake([
            'fantasy-api.llt-services.com/*' => Http::response([
                'id' => 1,
                'updatedAt' => '2026-09-01T00:00:00Z',
                'team' => ['id' => '38060111', 'teamValue' => 226458441, 'teamPoints' => 114],
                'formation' => [
                    'goalkeeper' => [$this->playerSlot('1', 'GK One', 12_000_000)],
                    'defender' => [$this->playerSlot('2', 'DF One', 8_000_000)],
                    'midfield' => [],
                    'striker' => [],
                    'bench' => [$this->playerSlot('3', 'Bench One', 3_000_000)],
                    'coach' => [['playerMaster' => ['id' => 'coach-1', 'name' => 'Coach']]],
                    'tacticalFormation' => '1-4-3-3',
                ],
            ], 200),
        ]);

        $entries = (new FantasyTeamService(app(FantasyApiClient::class)))
            ->getLineup($this->account(), '38060111');

        $this->assertCount(3, $entries);

        $byId = collect($entries)->keyBy(fn ($e) => $e->player->externalId);

        $this->assertTrue($byId['1']->isStarter);
        $this->assertSame(12_000_000, $byId['1']->player->clauseValue);

        $this->assertTrue($byId['2']->isStarter);

        $this->assertFalse($byId['3']->isStarter);
        $this->assertSame(3_000_000, $byId['3']->player->clauseValue);

        // The top-level "team" metadata object must never be mistaken for a roster entry.
        $this->assertNull($byId->get('38060111'));
        $this->assertNull($byId->get('coach-1'));
    }

    public function test_missing_formation_returns_an_empty_roster_instead_of_crashing(): void
    {
        Http::fake(['fantasy-api.llt-services.com/*' => Http::response(['id' => 1, 'team' => ['id' => 'x']], 200)]);

        $entries = (new FantasyTeamService(app(FantasyApiClient::class)))
            ->getLineup($this->account(), 'x');

        $this->assertSame([], $entries);
    }
}
