<?php

namespace App\Services\ExternalData;

use App\Services\ExternalData\DTOs\FutbolFantasyRowDTO;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Scrapes futbolfantasy.com's public market analytics page — an UNOFFICIAL,
 * third-party source, not affiliated with LaLiga. It publishes 1/2/3/7/14/30
 * day value-change percentages per player as `data-*` attributes on each row,
 * which is the only place we've found any market-value history at all (see
 * the migration docblock). No login, no API — plain HTML, parsed defensively:
 * if their markup changes, this returns fewer/zero rows rather than throwing,
 * since this whole feature is explicitly non-critical.
 */
class FutbolFantasyClient
{
    /**
     * @return FutbolFantasyRowDTO[]
     */
    public function fetchMarketTrends(): array
    {
        $url = config('fantasy.external.futbolfantasy_market_url');

        try {
            $response = Http::withHeaders([
                'User-Agent' => config('fantasy.external.user_agent'),
                'Accept' => 'text/html',
            ])->timeout((int) config('fantasy.external.timeout'))->get($url);
        } catch (\Throwable $e) {
            Log::channel('fantasy_api')->warning('fantasy.external.futbolfantasy.network_error', ['message' => $e->getMessage()]);

            return [];
        }

        if ($response->failed()) {
            Log::channel('fantasy_api')->warning('fantasy.external.futbolfantasy.http_error', ['status' => $response->status()]);

            return [];
        }

        return $this->parse($response->body());
    }

    /**
     * @return FutbolFantasyRowDTO[]
     */
    public function parse(string $html): array
    {
        if (! preg_match_all('/<tr class="elemento_jugador.*?<\/tr>/s', $html, $rowMatches)) {
            return [];
        }

        $rows = [];

        foreach ($rowMatches[0] as $chunk) {
            $attrs = [];
            preg_match_all('/data-([\w-]+)="([^"]*)"/', $chunk, $attrMatches, PREG_SET_ORDER);
            foreach ($attrMatches as $m) {
                $attrs[$m[1]] = html_entity_decode($m[2]);
            }

            $displayName = null;
            if (preg_match('/class="player-name">.*?<span[^>]*>([^<]+)<\/span>/s', $chunk, $m)) {
                $displayName = trim(html_entity_decode($m[1]));
            }

            $clubName = null;
            if (preg_match('/class="player-equipo">.*?<span>([^<]+)<\/span>/s', $chunk, $m)) {
                $clubName = trim(html_entity_decode($m[1]));
            }

            $decelerating = null;
            if (str_contains($chunk, 'Desacelera')) {
                $decelerating = true;
            } elseif (str_contains($chunk, '"Acelera"')) {
                $decelerating = false;
            }

            $row = FutbolFantasyRowDTO::fromAttributes($attrs, $displayName, $clubName, $decelerating);

            if ($row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
