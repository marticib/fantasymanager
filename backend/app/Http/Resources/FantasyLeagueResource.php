<?php

namespace App\Http\Resources;

use App\Models\FantasyLeague;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FantasyLeague */
class FantasyLeagueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'externalId' => $this->external_id,
            'name' => $this->name,
            'mode' => $this->mode,
            'teamCount' => $this->team_count,
        ];
    }
}
