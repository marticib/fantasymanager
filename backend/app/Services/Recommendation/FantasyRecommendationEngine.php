<?php

namespace App\Services\Recommendation;

use App\Contracts\RecommendationReasoningInterface;
use App\Models\FantasyAccount;
use App\Models\FantasyMarketPlayer;
use App\Models\FantasyRecommendation;
use App\Models\FantasyTeamPlayer;
use App\Services\Recommendation\ValueObjects\PlayerTrend;
use App\Services\Recommendation\ValueObjects\RecommendationDraft;
use Illuminate\Support\Collection;

/**
 * Turns synced data (roster, market, snapshots) into concrete BUY / SELL /
 * HOLD decisions. Every number here is computed deterministically from
 * fantasy_player_snapshots + fantasy_settings — nothing is guessed or left
 * to an LLM (see RecommendationReasoningInterface). MVP scope: BUY, SELL,
 * HOLD only; PAY_CLAUSE/trading/lineup actions are layered on in later
 * phases without touching this decision core.
 */
class FantasyRecommendationEngine
{
    public function __construct(
        private readonly TrendAnalysisService $trendService,
        private readonly FantasyScoreService $scoreService,
        private readonly FantasySettingsService $settings,
        private readonly RecommendationReasoningInterface $reasoning,
    ) {}

    /**
     * Computes fresh recommendations and persists them, expiring whatever
     * was previously ACTIVE for this account. Returns the newly created rows.
     */
    public function generate(FantasyAccount $account): Collection
    {
        $team = $account->activeTeam;
        $league = $account->activeLeague;

        if (! $team || ! $league) {
            return collect();
        }

        $rules = $this->settings->rules($account);
        $drafts = collect();

        foreach ($team->teamPlayers()->with('player')->get() as $teamPlayer) {
            if ($teamPlayer->player) {
                $drafts->push($this->evaluateOwnedPlayer($teamPlayer, $account, $rules));
            }
        }

        $ownedPlayerIds = $team->players()->pluck('fantasy_players.id')->all();

        $marketListings = FantasyMarketPlayer::query()
            ->where('fantasy_league_id', $league->id)
            ->where('is_on_market', true)
            ->whereNotIn('fantasy_player_id', $ownedPlayerIds)
            ->with('player')
            ->get();

        $availableCapital = max(0, (int) ($team->money ?? 0) - (int) $rules['minimum_cash_reserve']);

        foreach ($marketListings as $listing) {
            if (! $listing->player) {
                continue;
            }

            $draft = $this->evaluateMarketPlayer($listing, $account, $rules, $availableCapital);
            if ($draft) {
                $drafts->push($draft);
            }
        }

        return $this->persist($account, $league->id, $drafts);
    }

    private function evaluateOwnedPlayer(FantasyTeamPlayer $teamPlayer, FantasyAccount $account, array $rules): RecommendationDraft
    {
        $player = $teamPlayer->player;
        $trend = $this->trendService->analyze($player);
        $score = $this->scoreService->compute($player, $account, $trend);

        $dailyDropBreached = $trend->change24h !== null && $trend->change24h <= $rules['sell_daily_drop_threshold'];
        $threeDayDropBreached = $trend->change3d !== null && $trend->change3d <= $rules['sell_3d_drop_threshold'];
        $sellSignal = $dailyDropBreached || $threeDayDropBreached;

        $pros = [];
        $cons = [];

        if ($trend->change24h !== null) {
            $line = sprintf('Valor %s%s/dia', $trend->change24h >= 0 ? '+' : '', $this->money($trend->change24h));
            $trend->change24h >= 0 ? $pros[] = $line : $cons[] = $line;
        }
        if ((float) $player->average_points > 0) {
            $pros[] = sprintf('%.1f punts de mitjana', (float) $player->average_points);
        }
        if ($score->breakdown['starter_likelihood'] >= 70) {
            $pros[] = 'Titular habitual';
        } elseif ($score->breakdown['starter_likelihood'] <= 40) {
            $cons[] = 'Dubtes sobre titularitat';
        }

        // A strong Fantasy Score outweighs a value dip — matches the product
        // spec's example: don't sell a high performer over a small drop.
        if ($sellSignal && $score->total >= 70) {
            return new RecommendationDraft(
                action: FantasyRecommendation::ACTION_HOLD,
                fantasyPlayerId: $player->id,
                priority: FantasyRecommendation::PRIORITY_LOW,
                confidence: $score->confidence,
                reason: "La baixada de valor no compensa perdre un jugador d'alt rendiment (Fantasy Score {$score->total}).",
                pros: $pros,
                cons: $cons,
                financialImpact: $trend->change3d,
                recommendedAmount: null,
                maxAmount: null,
                fantasyScore: $score->total,
                expiresAt: now()->addHours(24),
            );
        }

        if ($sellSignal) {
            $priority = ($trend->classification === PlayerTrend::MOLT_BAIXISTA || $dailyDropBreached)
                ? FantasyRecommendation::PRIORITY_CRITICAL
                : FantasyRecommendation::PRIORITY_HIGH;

            return new RecommendationDraft(
                action: FantasyRecommendation::ACTION_SELL,
                fantasyPlayerId: $player->id,
                priority: $priority,
                confidence: $score->confidence,
                reason: 'La pèrdua prevista de valor supera el valor esportiu esperat. Fes-ho abans de la pròxima actualització del mercat.',
                pros: $pros,
                cons: $cons,
                financialImpact: $trend->change3d,
                recommendedAmount: null,
                maxAmount: null,
                fantasyScore: $score->total,
                expiresAt: now()->addHours(6),
            );
        }

        return new RecommendationDraft(
            action: FantasyRecommendation::ACTION_HOLD,
            fantasyPlayerId: $player->id,
            priority: FantasyRecommendation::PRIORITY_LOW,
            confidence: $score->confidence,
            reason: 'Rendiment i valor estables; sense necessitat d\'actuar avui.',
            pros: $pros,
            cons: $cons,
            financialImpact: null,
            recommendedAmount: null,
            maxAmount: null,
            fantasyScore: $score->total,
            expiresAt: now()->addHours(24),
        );
    }

    private function evaluateMarketPlayer(
        FantasyMarketPlayer $listing,
        FantasyAccount $account,
        array $rules,
        int $availableCapital,
    ): ?RecommendationDraft {
        $player = $listing->player;
        $trend = $this->trendService->analyze($player);
        $score = $this->scoreService->compute($player, $account, $trend);

        $minConfidence = 35;
        if ($score->confidence < $minConfidence || $trend->isFalling()) {
            return null;
        }

        $growthOk = $trend->change24h === null || $trend->change24h >= $rules['buy_growth_threshold'];
        $buySignal = $score->total >= 65 && $growthOk;

        if (! $buySignal) {
            return null;
        }

        $marketValue = $listing->market_value ?? $player->market_value ?? 0;
        $recommendedBid = (int) round($marketValue * 1.03);
        $maxBid = (int) round($marketValue * (1 + ($rules['maximum_bid_over_market_percentage'] / 100)));

        if ($maxBid > $availableCapital) {
            // Still worth surfacing (it's a real opportunity), but cap what we'd
            // suggest bidding at what's actually affordable, and flag it.
            $maxBid = $availableCapital;
            $recommendedBid = min($recommendedBid, $availableCapital);
        }

        if ($recommendedBid <= 0) {
            return null;
        }

        $pros = [
            sprintf('Fantasy Score %.0f/100', $score->total),
            sprintf('%.1f punts de mitjana', (float) $player->average_points),
        ];
        if ($trend->pctChange3d !== null) {
            $pros[] = sprintf('Valor %s%.1f%% en 3 dies (%s)', $trend->pctChange3d >= 0 ? '+' : '', $trend->pctChange3d, $trend->label());
        }
        $cons = [];
        if ($score->breakdown['risk'] < 70) {
            $cons[] = 'Risc físic/rotació moderat';
        }
        if ($maxBid >= $availableCapital && $availableCapital > 0) {
            $cons[] = 'Oferta limitada pel saldo disponible';
        }

        $priority = ($trend->classification === PlayerTrend::MOLT_ALCISTA || $score->total >= 80)
            ? FantasyRecommendation::PRIORITY_HIGH
            : FantasyRecommendation::PRIORITY_MEDIUM;

        return new RecommendationDraft(
            action: FantasyRecommendation::ACTION_BUY,
            fantasyPlayerId: $player->id,
            priority: $priority,
            confidence: $score->confidence,
            reason: sprintf(
                'Valor %s, titular habitual i %.1f punts de mitjana amb tendència %s.',
                $this->money($marketValue),
                (float) $player->average_points,
                $trend->label(),
            ),
            pros: $pros,
            cons: $cons,
            financialImpact: null,
            recommendedAmount: $recommendedBid,
            maxAmount: $maxBid,
            fantasyScore: $score->total,
            expiresAt: $listing->expires_at ?? now()->addHours((int) $this->settings->get('recommendation_validity_hours', 24, $account)),
        );
    }

    /**
     * @param  Collection<int, RecommendationDraft>  $drafts
     */
    private function persist(FantasyAccount $account, int $leagueId, Collection $drafts): Collection
    {
        FantasyRecommendation::query()
            ->where('fantasy_account_id', $account->id)
            ->where('status', FantasyRecommendation::STATUS_ACTIVE)
            ->update(['status' => FantasyRecommendation::STATUS_EXPIRED]);

        $priorityOrder = [
            FantasyRecommendation::PRIORITY_CRITICAL => 0,
            FantasyRecommendation::PRIORITY_HIGH => 1,
            FantasyRecommendation::PRIORITY_MEDIUM => 2,
            FantasyRecommendation::PRIORITY_LOW => 3,
        ];

        $sorted = $drafts->sortBy(fn (RecommendationDraft $d) => [$priorityOrder[$d->priority] ?? 9, -$d->confidence])->values();

        return $sorted->map(function (RecommendationDraft $draft) use ($account, $leagueId) {
            $explanation = $this->reasoning->explain($draft);

            return FantasyRecommendation::create([
                'fantasy_account_id' => $account->id,
                'fantasy_league_id' => $leagueId,
                'fantasy_player_id' => $draft->fantasyPlayerId,
                'action' => $draft->action,
                'priority' => $draft->priority,
                'confidence' => $draft->confidence,
                'reason' => $explanation['reason'],
                'explanation' => ['pros' => $explanation['pros'], 'cons' => $explanation['cons']],
                'financial_impact' => $draft->financialImpact,
                'recommended_amount' => $draft->recommendedAmount,
                'max_amount' => $draft->maxAmount,
                'fantasy_score' => $draft->fantasyScore,
                'status' => FantasyRecommendation::STATUS_ACTIVE,
                'expires_at' => $draft->expiresAt,
                'generated_at' => now(),
            ]);
        });
    }

    private function money(int $amount): string
    {
        return number_format($amount, 0, ',', '.').' €';
    }
}
