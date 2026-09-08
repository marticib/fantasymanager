<?php

namespace Tests\Unit\Services\ExternalData;

use App\Models\FantasyExternalTrend;
use App\Models\FantasyPlayer;
use App\Services\ExternalData\FutbolFantasyClient;
use App\Services\ExternalData\FutbolFantasySyncService;
use App\Services\ExternalData\PlayerNameMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FutbolFantasySyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixtureHtml(): string
    {
        return <<<'HTML'
        <tr class="elemento_jugador filters_ok"
            data-id="1059" data-nombre="antonio sivera" data-posicion="Portero" data-equipo="28"
            data-valor="42294959"
            data-diferencia-pct1="0.53" data-diferencia-pct3="1.92" data-diferencia-pct7="6.58"
            data-tendencia="61">
            <a class="player-name"><span class="d-none d-md-inline">Antonio Sivera</span></a>
            <div class="player-equipo"><span>Alavés</span></div>
        </tr>
        <tr class="elemento_jugador filters_ok"
            data-id="9999" data-nombre="jugador desconegut" data-posicion="Delantero" data-equipo="1"
            data-valor="1000000" data-diferencia-pct1="0" data-tendencia="1">
            <a class="player-name"><span class="d-none d-md-inline">Jugador Desconegut</span></a>
            <div class="player-equipo"><span>Cap Equip</span></div>
        </tr>
        HTML;
    }

    public function test_sync_matches_and_stores_only_known_players(): void
    {
        $known = FantasyPlayer::create([
            'external_id' => '1059-la',
            'name' => 'Sivera',
            'nickname' => 'Sivera',
            'club_name' => 'Deportivo Alavés',
        ]);

        Http::fake(['futbolfantasy.com/*' => Http::response($this->fixtureHtml(), 200)]);

        $service = new FutbolFantasySyncService(new FutbolFantasyClient, new PlayerNameMatcher);
        $result = $service->sync();

        $this->assertSame(2, $result['total']);
        $this->assertSame(1, $result['matched']);
        $this->assertSame(1, $result['unmatched']);

        $trend = FantasyExternalTrend::where('fantasy_player_id', $known->id)->first();
        $this->assertNotNull($trend);
        $this->assertSame('futbolfantasy', $trend->source);
        $this->assertSame('suffix', $trend->match_confidence);
        $this->assertSame(42294959, $trend->value_now);
        $this->assertEqualsWithDelta(6.58, (float) $trend->pct_7d, 0.01);
        $this->assertSame(1, FantasyExternalTrend::count());
    }

    public function test_sync_upserts_on_a_second_run_instead_of_duplicating(): void
    {
        FantasyPlayer::create([
            'external_id' => '1059-la',
            'name' => 'Sivera',
            'nickname' => 'Sivera',
            'club_name' => 'Deportivo Alavés',
        ]);

        Http::fake(['futbolfantasy.com/*' => Http::response($this->fixtureHtml(), 200)]);

        $service = new FutbolFantasySyncService(new FutbolFantasyClient, new PlayerNameMatcher);
        $service->sync();
        $service->sync();

        $this->assertSame(1, FantasyExternalTrend::count());
    }

    public function test_a_failed_fetch_returns_zeroes_without_throwing(): void
    {
        Http::fake(['futbolfantasy.com/*' => Http::response('', 500)]);

        $service = new FutbolFantasySyncService(new FutbolFantasyClient, new PlayerNameMatcher);
        $result = $service->sync();

        $this->assertSame(['total' => 0, 'matched' => 0, 'unmatched' => 0], $result);
    }
}
