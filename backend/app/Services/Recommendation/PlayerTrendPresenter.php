<?php

namespace App\Services\Recommendation;

use App\Models\FantasyAccount;
use App\Models\FantasyExternalTrend;
use App\Models\FantasyPlayer;
use App\Services\ExternalData\SparklineHistoryBuilder;
use App\Services\Recommendation\ValueObjects\PlayerTrendPresentation;

/**
 * Bundles the trend/score/history work shared by every screen that shows a
 * player row with a value line and a real-or-fallback trend (Market, Team,
 * PlayerDetail): own TrendAnalysisService read, Fantasy Score, the
 * own-vs-external effective classification, and the sparkline history —
 * all in one place so those three controllers can't quietly drift out of
 * sync on how any of this is computed (see TrendAnalysisService and
 * SparklineHistoryBuilder for why each piece works the way it does).
 */
class PlayerTrendPresenter
{
    public function __construct(
        private readonly TrendAnalysisService $trendService,
        private readonly FantasyScoreService $scoreService,
        private readonly SparklineHistoryBuilder $sparklineBuilder,
    ) {}

    public function present(FantasyPlayer $player, FantasyAccount $account, ?FantasyExternalTrend $externalTrend, ?int $currentValue = null): PlayerTrendPresentation
    {
        $trend = $this->trendService->analyze($player);
        $score = $this->scoreService->compute($player, $account, $trend);
        $effective = $this->trendService->effectiveClassification($trend, $externalTrend);

        $ownHistory = $player->snapshots()
            ->whereNotNull('market_value')
            ->orderByDesc('captured_at')
            ->limit(10)
            ->get(['market_value', 'captured_at'])
            ->reverse()
            ->values()
            ->map(fn ($s) => ['value' => $s->market_value, 'capturedAt' => $s->captured_at->toIso8601String()]);

        [$history, $historySource] = $this->sparklineBuilder->build(
            $ownHistory,
            $externalTrend,
            $currentValue ?? ($player->market_value ?? 0),
        );

        return new PlayerTrendPresentation(
            trend: $trend,
            score: $score,
            effectiveClassification: $effective['classification'],
            effectiveSource: $effective['source'],
            externalTrend: $externalTrend,
            history: $history,
            historySource: $historySource,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function externalTrendPayload(?FantasyExternalTrend $externalTrend): ?array
    {
        if (! $externalTrend) {
            return null;
        }

        // A real euro figure from the external source itself — value_now/
        // value_1d are the actual scraped values (data-valor1 etc.), never
        // derived from pct_1d, so subtracting them isn't computing a new
        // fact, just expressing the same real scrape in euros instead of %.
        $delta1d = ($externalTrend->value_now !== null && $externalTrend->value_1d !== null)
            ? (int) $externalTrend->value_now - (int) $externalTrend->value_1d
            : null;

        return [
            'source' => $externalTrend->source,
            'delta1d' => $delta1d,
            'pct1d' => $externalTrend->pct_1d !== null ? (float) $externalTrend->pct_1d : null,
            'pct3d' => $externalTrend->pct_3d !== null ? (float) $externalTrend->pct_3d : null,
            'pct7d' => $externalTrend->pct_7d !== null ? (float) $externalTrend->pct_7d : null,
            'pct14d' => $externalTrend->pct_14d !== null ? (float) $externalTrend->pct_14d : null,
            'pct30d' => $externalTrend->pct_30d !== null ? (float) $externalTrend->pct_30d : null,
            'trendDays' => $externalTrend->trend_days,
            'decelerating' => $externalTrend->decelerating,
            'matchConfidence' => $externalTrend->match_confidence,
            'fetchedAt' => $externalTrend->fetched_at->toIso8601String(),
        ];
    }
}
