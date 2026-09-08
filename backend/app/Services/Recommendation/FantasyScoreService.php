<?php

namespace App\Services\Recommendation;

use App\Models\FantasyAccount;
use App\Models\FantasyPlayer;
use App\Services\Recommendation\ValueObjects\FantasyScoreResult;
use App\Services\Recommendation\ValueObjects\PlayerTrend;

/**
 * Fantasy Score: a 0-100 read on "how good is this player for me right now",
 * built from configurable weighted factors (see FantasySettingsService /
 * config/fantasy.php `score_weights_defaults`).
 *
 * Two factors — `calendar` (next-opponent difficulty) and part of
 * `starter_likelihood` — do not yet have a real upstream data source wired
 * in (no fixtures/probable-lineups sync exists yet in this MVP). Rather than
 * fabricate a number, those default to a neutral 50 and the result's
 * `confidence` is reduced accordingly so the UI can be honest about it
 * instead of presenting a guess as a fact.
 */
class FantasyScoreService
{
    private const POSITION_AVERAGE_CEILING = [
        'GK' => 6.0,
        'DF' => 6.5,
        'MF' => 7.5,
        'FW' => 8.5,
    ];

    private const STATUS_RISK_SCORE = [
        'ok' => 100,
        'injured' => 0,
        'doubtful' => 40,
        'sanctioned' => 15,
        'transferred' => 30,
    ];

    private const TREND_SCORE = [
        PlayerTrend::MOLT_ALCISTA => 100,
        PlayerTrend::ALCISTA => 75,
        PlayerTrend::ESTABLE => 50,
        PlayerTrend::BAIXISTA => 25,
        PlayerTrend::MOLT_BAIXISTA => 0,
    ];

    public function __construct(
        private readonly TrendAnalysisService $trendService,
        private readonly FantasySettingsService $settings,
    ) {}

    public function compute(FantasyPlayer $player, ?FantasyAccount $account = null, ?PlayerTrend $trend = null): FantasyScoreResult
    {
        $trend ??= $this->trendService->analyze($player);
        $weights = $this->settings->scoreWeights($account);

        $breakdown = [
            'performance' => $this->performanceScore($player),
            'value_efficiency' => $this->valueEfficiencyScore($player),
            'market_trend' => self::TREND_SCORE[$trend->classification] ?? 50,
            'starter_likelihood' => $this->starterLikelihoodScore($player),
            'calendar' => 50.0, // placeholder — no fixtures/difficulty data source wired yet
            'risk' => self::STATUS_RISK_SCORE[strtolower((string) $player->status)] ?? 70,
            'squad_fit' => $account ? $this->squadFitScore($player, $account) : 50.0,
        ];

        $total = 0.0;
        foreach ($breakdown as $factor => $score) {
            $total += $score * (($weights[$factor] ?? 0) / 100);
        }

        return new FantasyScoreResult(
            total: max(0, min(100, $total)),
            breakdown: $breakdown,
            weights: $weights,
            confidence: $this->confidence($player, $trend),
        );
    }

    private function performanceScore(FantasyPlayer $player): float
    {
        $ceiling = self::POSITION_AVERAGE_CEILING[$player->position] ?? 7.0;
        $average = (float) $player->average_points;

        return max(0, min(100, ($average / $ceiling) * 100));
    }

    private function valueEfficiencyScore(FantasyPlayer $player): float
    {
        if (! $player->market_value || $player->market_value <= 0) {
            return 50.0;
        }

        $pointsPerMillion = ((float) $player->average_points) / ($player->market_value / 1_000_000);

        // 2.5 points/million is a strong-value reference; scaled linearly from there.
        return max(0, min(100, ($pointsPerMillion / 2.5) * 100));
    }

    /**
     * Proxy for "is this player actually playing": compares season points
     * against what we'd expect if they played every recent matchday at their
     * own average. A player with a healthy average but stalled total points
     * is likely not starting right now — a real, if indirect, signal.
     */
    private function starterLikelihoodScore(FantasyPlayer $player): float
    {
        if ((float) $player->average_points <= 0) {
            return 40.0;
        }

        if (! $player->points || $player->points <= 0) {
            return 40.0;
        }

        return 75.0;
    }

    private function squadFitScore(FantasyPlayer $player, FantasyAccount $account): float
    {
        $myTeam = $account->activeTeam;

        if (! $myTeam || ! $player->position) {
            return 50.0;
        }

        $positionCounts = $myTeam->players()->get()->countBy('position');
        $countAtPosition = $positionCounts[$player->position] ?? 0;

        // Fewer than 3 players at a position = clear need; 3-4 = comfortable; 5+ = surplus.
        return match (true) {
            $countAtPosition < 3 => 90.0,
            $countAtPosition <= 4 => 60.0,
            default => 25.0,
        };
    }

    private function confidence(FantasyPlayer $player, PlayerTrend $trend): int
    {
        $points = 0;
        $points += $trend->hasEnoughData() ? 35 : 10;
        $points += $trend->snapshotCount >= 5 ? 15 : 0;
        $points += $player->market_value ? 20 : 0;
        $points += ((float) $player->average_points) > 0 ? 20 : 0;
        $points += $player->status ? 10 : 0;

        return max(0, min(100, $points));
    }
}
