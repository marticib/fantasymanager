<?php

namespace App\Services\Recommendation\ValueObjects;

/**
 * The full output of MarketBuyAnalysisService::analyze() for one market
 * listing at one candidate price: is buying this player at this price
 * likely to pay for itself through the player's own value growth? Mirrors
 * ClauseEconomicAnalysis's shape and conventions — every percentage
 * (`growth*`, `expectedDailyGrowth`, `expectedROI*`, `expectedWinningPremium`)
 * is a decimal fraction (0.125, not 12.5), the UI multiplies by 100.
 *
 * `recommendedBid` is either the estimated winning bid (float) or the
 * literal string `'DO_NOT_CHASE'` when that estimate exceeds `maxBid` —
 * chasing the auction further would no longer be economically justified, so
 * the field says that directly rather than returning a number that looks
 * like a suggestion to bid.
 */
class MarketBuyAnalysis
{
    public function __construct(
        public readonly float $currentMarketValue,
        public readonly float $acquisitionPrice,
        public readonly ?float $growth1d,
        public readonly ?float $growth3d,
        public readonly ?float $growth7d,
        public readonly float $expectedDailyGrowth,
        public readonly float $projectedValue3d,
        public readonly float $projectedValue7d,
        public readonly float $projectedValue14d,
        public readonly float $expectedProfit3d,
        public readonly float $expectedProfit7d,
        public readonly float $expectedProfit14d,
        public readonly float $expectedROI3d,
        public readonly float $expectedROI7d,
        public readonly float $expectedROI14d,
        public readonly ?int $breakEvenDays,
        public readonly int $buyEconomicScore,
        public readonly string $classification,
        public readonly string $recommendation,
        public readonly float $maxBid,
        public readonly float|string $recommendedBid,
        public readonly ?int $bidCount,
        public readonly float $expectedWinningPremium,
        public readonly float $estimatedWinningBid,
        public readonly string $auctionHistorySource,
        public readonly int $dataQuality,
    ) {}

    public function toArray(): array
    {
        return [
            'currentMarketValue' => $this->currentMarketValue,
            'acquisitionPrice' => $this->acquisitionPrice,
            'growth1d' => $this->growth1d,
            'growth3d' => $this->growth3d,
            'growth7d' => $this->growth7d,
            'expectedDailyGrowth' => $this->expectedDailyGrowth,
            'projectedValue3d' => $this->projectedValue3d,
            'projectedValue7d' => $this->projectedValue7d,
            'projectedValue14d' => $this->projectedValue14d,
            'expectedProfit3d' => $this->expectedProfit3d,
            'expectedProfit7d' => $this->expectedProfit7d,
            'expectedProfit14d' => $this->expectedProfit14d,
            'expectedROI3d' => $this->expectedROI3d,
            'expectedROI7d' => $this->expectedROI7d,
            'expectedROI14d' => $this->expectedROI14d,
            'breakEvenDays' => $this->breakEvenDays,
            'buyEconomicScore' => $this->buyEconomicScore,
            'classification' => $this->classification,
            'recommendation' => $this->recommendation,
            'maxBid' => $this->maxBid,
            'recommendedBid' => $this->recommendedBid,
            'bidCount' => $this->bidCount,
            'expectedWinningPremium' => $this->expectedWinningPremium,
            'estimatedWinningBid' => $this->estimatedWinningBid,
            'auctionHistorySource' => $this->auctionHistorySource,
            'dataQuality' => $this->dataQuality,
        ];
    }
}
