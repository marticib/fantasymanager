<?php

namespace App\Http\Resources;

use App\Models\FantasyTeam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FantasyTeam */
class FantasyTeamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'externalId' => $this->external_id,
            'name' => $this->name,
            'managerName' => $this->manager_name,
            'money' => $this->money,
            'teamValue' => $this->team_value,
        ];
    }
}
