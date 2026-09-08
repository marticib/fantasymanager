<?php

namespace App\Http\Resources;

use App\Models\FantasyRecommendation;
use App\Services\Recommendation\TrendAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FantasyRecommendation */
class FantasyRecommendationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'priority' => $this->priority,
            'confidence' => $this->confidence,
            'player' => $this->whenLoaded('player', fn () => [
                'id' => $this->player->id,
                'name' => $this->player->name,
                'club' => $this->player->club_name,
                'position' => $this->player->position,
                'imageUrl' => $this->player->image_url,
                'marketValue' => $this->player->market_value,
                'averagePoints' => $this->player->average_points !== null ? (float) $this->player->average_points : null,
            ]),
            'playerId' => $this->fantasy_player_id,
            // Today's value move, recomputed live from snapshots rather than
            // stored on the recommendation — it drifts by the minute while
            // the recommendation itself only regenerates a few times a day.
            'changeToday' => $this->whenLoaded('player', fn () => $this->player
                ? app(TrendAnalysisService::class)->analyze($this->player)->change24h
                : null),
            'reason' => $this->reason,
            'pros' => $this->explanation['pros'] ?? [],
            'cons' => $this->explanation['cons'] ?? [],
            'financialImpact' => $this->financial_impact,
            'recommendedAmount' => $this->recommended_amount,
            'maxAmount' => $this->max_amount,
            'fantasyScore' => $this->fantasy_score !== null ? (float) $this->fantasy_score : null,
            'status' => $this->status,
            'expiresAt' => $this->expires_at?->toIso8601String(),
            'generatedAt' => $this->generated_at?->toIso8601String(),
        ];
    }
}
