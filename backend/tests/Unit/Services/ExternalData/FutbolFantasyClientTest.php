<?php

namespace Tests\Unit\Services\ExternalData;

use App\Services\ExternalData\FutbolFantasyClient;
use PHPUnit\Framework\TestCase;

/**
 * A trimmed fixture mirroring the real futbolfantasy.com markup (confirmed
 * live: multi-line data-* attributes on the <tr>, nested spans for the
 * player/club display name). Deliberately not a full page dump — just
 * enough structure to prove the parser survives it.
 */
class FutbolFantasyClientTest extends TestCase
{
    private function fixtureHtml(): string
    {
        return <<<'HTML'
        <table><tbody>
        <tr class="elemento_jugador filters_ok d-none clickable"
            data-id="1059"
            data-nombre="antonio sivera"
            data-posicion="Portero"
            data-equipo="28"
            data-valor="42294959"
            data-valor1="42069630"
            data-diferencia-pct1="0.5356096547557"
            data-diferencia-pct2="1.3927073173626"
            data-diferencia-pct3="1.9261273318953"
            data-diferencia-pct7="6.5894603221326"
            data-diferencia-pct14="15.449437082794"
            data-diferencia-pct30="47.368768933525"
            data-tendencia="61"
            data-aceleracion="-61637"
            onclick="app.Analytics.showPlayerDetail('laliga-fantasy','','1059');"
            >
            <td class="sticky-col">
                <div class="player-widget">
                    <div class="player-info">
                        <a href="#" class="player-name"><span class="d-none d-md-inline">Antonio Sivera</span><span class="d-inline d-md-none">Sivera</span></a>
                        <div class="player-equipo">
                            <img src="x.png" alt="Alavés">
                            <span>Alavés</span>
                        </div>
                    </div>
                </div>
            </td>
            <td class="text-center" style="font-size:16px;"><i class="fas fa-angle-down text-success hideQtip" data-tooltip="Desacelera"></i></td>
        </tr>
        <tr class="elemento_jugador filters_ok d-none clickable"
            data-id="2200"
            data-nombre="jugador sense valor"
            data-posicion="Delantero"
            data-equipo="5"
            data-tendencia=""
            >
            <td class="sticky-col">
                <div class="player-widget">
                    <div class="player-info">
                        <a href="#" class="player-name"><span class="d-none d-md-inline">Jugador Sense Valor</span></a>
                        <div class="player-equipo"><span>Equip Fantasma</span></div>
                    </div>
                </div>
            </td>
        </tr>
        <tr class="not-a-player-row"><td>irrelevant</td></tr>
        </tbody></table>
        HTML;
    }

    public function test_parses_real_shaped_rows_into_dtos(): void
    {
        $rows = (new FutbolFantasyClient)->parse($this->fixtureHtml());

        $this->assertCount(2, $rows);

        $sivera = $rows[0];
        $this->assertSame('1059', $sivera->externalId);
        $this->assertSame('Antonio Sivera', $sivera->rawName);
        $this->assertSame('Portero', $sivera->position);
        $this->assertSame('Alavés', $sivera->clubName);
        $this->assertSame(42294959, $sivera->valueNow);
        $this->assertSame(42069630, $sivera->value1d);
        $this->assertEqualsWithDelta(0.54, $sivera->pct1d, 0.01);
        $this->assertEqualsWithDelta(47.37, $sivera->pct30d, 0.01);
        $this->assertSame(61, $sivera->trendDays);
        $this->assertTrue($sivera->decelerating);
    }

    public function test_a_row_with_no_value_still_parses_with_null_numerics(): void
    {
        $rows = (new FutbolFantasyClient)->parse($this->fixtureHtml());
        $noValue = $rows[1];

        $this->assertSame('Jugador Sense Valor', $noValue->rawName);
        $this->assertNull($noValue->valueNow);
        $this->assertNull($noValue->trendDays);
        $this->assertNull($noValue->decelerating);
    }

    public function test_malformed_html_returns_an_empty_array_instead_of_throwing(): void
    {
        $rows = (new FutbolFantasyClient)->parse('<html><body>no players here</body></html>');

        $this->assertSame([], $rows);
    }
}
