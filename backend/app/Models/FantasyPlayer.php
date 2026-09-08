<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FantasyPlayer extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_id',
        'name',
        'nickname',
        'club_name',
        'club_external_id',
        'position',
        'image_url',
        'status',
        'points',
        'average_points',
        'market_value',
        'previous_market_value',
        'raw_payload',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'average_points' => 'decimal:2',
            'raw_payload' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(FantasyPlayerSnapshot::class)->orderBy('captured_at');
    }

    public function teamPlayers(): HasMany
    {
        return $this->hasMany(FantasyTeamPlayer::class);
    }

    public function marketListings(): HasMany
    {
        return $this->hasMany(FantasyMarketPlayer::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(FantasyRecommendation::class);
    }

    public function latestSnapshot(): ?FantasyPlayerSnapshot
    {
        return $this->snapshots()->latest('captured_at')->first();
    }

    /**
     * Current owner across all synced leagues, if any (null = free agent / on the market).
     */
    public function currentOwner(): ?FantasyTeam
    {
        $teamPlayer = $this->teamPlayers()->latest('id')->first();

        return $teamPlayer?->team;
    }
}
