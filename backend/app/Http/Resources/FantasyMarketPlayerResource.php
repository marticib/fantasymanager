<?php

namespace App\Http\Resources;

use App\Models\FantasyMarketPlayer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FantasyMarketPlayer */
class FantasyMarketPlayerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'player' => new FantasyPlayerResource($this->whenLoaded('player')),
            'sellerTeam' => $this->whenLoaded('sellerTeam', fn () => $this->sellerTeam?->name),
            'marketValue' => $this->market_value,
            'askingPrice' => $this->asking_price,
            'expiresAt' => $this->expires_at?->toIso8601String(),
        ];
    }
}
