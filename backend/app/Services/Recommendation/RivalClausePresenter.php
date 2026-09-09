<?php

namespace App\Services\Recommendation;

use App\Models\FantasyExternalTrend;
use App\Models\FantasyPlayer;
use App\Models\FantasyTeamPlayer;
use App\Services\Recommendation\ValueObjects\ClauseEconomicAnalysis;

/**
 * Turns a rival-owned player's ClauseEconomicAnalysisService output into the
 * same {type, action, mainScore, confidence, favors, risks, raw, projections}
 * presentation shape the individual player page already uses for its
 * OWNED_BY_RIVAL context (PlayerController::show()) — extracted here so a
 * rival team's full roster (TeamController::rival()) reuses it per player
 * instead of a second copy of this formatting.
 */
class RivalClausePresenter
{
    public function __construct(
        private readonly PlayerValueTrendCalculator $trendCalculator,
        private readonly ClauseEconomicAnalysisService $clauseAnalysisService,
    ) {}

    /**
     * @return array<string, mixed>|null null when the rival's clause value isn't known
     */
    public function present(FantasyPlayer $player, FantasyTeamPlayer $teamPlayer, ?FantasyExternalTrend $externalTrend): ?array
    {
        if ($teamPlayer->clause_value === null) {
            return null;
        }

        $marketValue = (float) ($player->market_value ?? 0);
        $clauseValue = (float) $teamPlayer->clause_value;
        [$value1d, $value3d, $value7d] = $this->trendCalculator->historicalValues($player, $marketValue, $externalTrend);

        $analysis = $this->clauseAnalysisService->analyze($marketValue, $clauseValue, $value1d, $value3d, $value7d);
        $confidence = $this->trendCalculator->dataQuality($analysis->growth1d, $analysis->growth3d, $analysis->growth7d);

        ['favors' => $favors, 'risks' => $risks] = $this->favorsRisks($analysis);

        $isLocked = $teamPlayer->clause_locked_until !== null && $teamPlayer->clause_locked_until->isFuture();

        return [
            'type' => 'CLAUSE',
            'action' => $analysis->economicRecommendation,
            'mainScore' => $analysis->clauseEconomicScore,
            'confidence' => $confidence,
            'reason' => null,
            'favors' => $favors,
            'risks' => $risks,
            'raw' => array_merge($analysis->toArray(), [
                'isLocked' => $isLocked,
                'daysUntilUnlock' => $isLocked ? (int) ceil(now()->diffInHours($teamPlayer->clause_locked_until) / 24) : null,
            ]),
            'projections' => ['value3d' => $analysis->expectedValue3d, 'value7d' => $analysis->expectedValue7d, 'value14d' => $analysis->expectedValue14d],
        ];
    }

    /**
     * @return array{favors: array<int, string>, risks: array<int, string>}
     */
    private function favorsRisks(ClauseEconomicAnalysis $analysis): array
    {
        $favors = [];
        $risks = [];

        if ($analysis->roi14d > 0) {
            $favors[] = sprintf('ROI a 14 dies +%s%%.', number_format($analysis->roi14d * 100, 1));
        }
        if ($analysis->breakEvenDays !== null && $analysis->breakEvenDays <= 5) {
            $favors[] = $analysis->breakEvenDays === 0 ? 'Ja recupera la prima avui mateix.' : sprintf('Break-even en %d dies.', $analysis->breakEvenDays);
        }
        if ($analysis->clausePremiumPct < 0.10) {
            $favors[] = sprintf('Prima de clàusula baixa (%s%%).', number_format($analysis->clausePremiumPct * 100, 1));
        }

        if ($analysis->breakEvenDays === null) {
            $risks[] = 'Sense break-even previst dins del termini simulat.';
        } elseif ($analysis->breakEvenDays > 10) {
            $risks[] = sprintf('Break-even llarg (%d dies).', $analysis->breakEvenDays);
        }
        if ($analysis->clausePremiumPct > 0.30) {
            $risks[] = sprintf('Prima de clàusula elevada (%s%%).', number_format($analysis->clausePremiumPct * 100, 1));
        }
        if ($analysis->growth1d !== null && $analysis->growth1d < 0) {
            $risks[] = 'Tendència de valor negativa.';
        }

        return ['favors' => $favors, 'risks' => $risks];
    }
}
