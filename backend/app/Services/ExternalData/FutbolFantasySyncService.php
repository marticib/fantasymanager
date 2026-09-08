<?php

namespace App\Services\ExternalData;

use App\Models\FantasyExternalTrend;
use App\Models\FantasyPlayer;

/**
 * Orchestrates the futbolfantasy.com scrape end to end: fetch, name-match,
 * store. This data is explicitly SUPPLEMENTARY:
 *
 *  - It is never read by FantasyScoreService or FantasyRecommendationEngine.
 *    Those only ever use fantasy_player_snapshots — our own, first-party,
 *    LaLiga-sourced history. Mixing an unverified third-party scrape into the
 *    numbers that drive a BUY/SELL decision would blur where a figure came
 *    from, which is the one thing this app promises never to do.
 *  - It's exposed to the API/UI as a clearly-labeled, separate "external
 *    trend" field so it's still useful to *look at* — full 30-day depth from
 *    day one, instead of waiting weeks for our own snapshots to accumulate —
 *    without pretending to be official.
 */
class FutbolFantasySyncService
{
    public function __construct(
        private readonly FutbolFantasyClient $client,
        private readonly PlayerNameMatcher $matcher,
    ) {}

    /**
     * @return array{total: int, matched: int, unmatched: int}
     */
    public function sync(): array
    {
        $rows = $this->client->fetchMarketTrends();

        if (empty($rows)) {
            return ['total' => 0, 'matched' => 0, 'unmatched' => 0];
        }

        $index = $this->matcher->buildIndex(FantasyPlayer::all(['id', 'name', 'nickname', 'club_name']));
        $now = now();
        $matched = 0;

        foreach ($rows as $row) {
            $result = $this->matcher->match($index, $row->rawName, $row->clubName);

            if (! $result['player']) {
                continue;
            }

            FantasyExternalTrend::updateOrCreate(
                ['fantasy_player_id' => $result['player']->id, 'source' => 'futbolfantasy'],
                [
                    'external_id' => $row->externalId,
                    'match_confidence' => $result['confidence'],
                    'value_now' => $row->valueNow,
                    'value_1d' => $row->value1d,
                    'value_3d' => $row->value3d,
                    'value_7d' => $row->value7d,
                    'value_14d' => $row->value14d,
                    'value_30d' => $row->value30d,
                    'pct_1d' => $row->pct1d,
                    'pct_2d' => $row->pct2d,
                    'pct_3d' => $row->pct3d,
                    'pct_7d' => $row->pct7d,
                    'pct_14d' => $row->pct14d,
                    'pct_30d' => $row->pct30d,
                    'trend_days' => $row->trendDays,
                    'decelerating' => $row->decelerating,
                    'fetched_at' => $now,
                ],
            );

            $matched++;
        }

        return ['total' => count($rows), 'matched' => $matched, 'unmatched' => count($rows) - $matched];
    }
}
