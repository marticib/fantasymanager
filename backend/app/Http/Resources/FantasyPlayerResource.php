<?php

namespace App\Http\Resources;

use App\Models\FantasyPlayer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FantasyPlayer */
class FantasyPlayerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'externalId' => $this->external_id,
            'name' => $this->name,
            'nickname' => $this->nickname,
            'club' => $this->club_name,
            'position' => $this->position,
            'imageUrl' => $this->image_url,
            'status' => $this->status,
            'points' => $this->points,
            'averagePoints' => (float) $this->average_points,
            'marketValue' => $this->market_value,
            'previousMarketValue' => $this->previous_market_value,
            'lastSyncedAt' => $this->last_synced_at?->toIso8601String(),
        ];
    }
}
