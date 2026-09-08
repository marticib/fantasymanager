<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FantasyLeague extends Model
{
    use HasFactory;

    protected $fillable = [
        'fantasy_account_id',
        'external_id',
        'name',
        'mode',
        'team_count',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FantasyAccount::class, 'fantasy_account_id');
    }

    public function teams(): HasMany
    {
        return $this->hasMany(FantasyTeam::class);
    }

    public function marketPlayers(): HasMany
    {
        return $this->hasMany(FantasyMarketPlayer::class);
    }

    public function standings(): HasMany
    {
        return $this->hasMany(FantasyStanding::class);
    }

    public function myTeam(): ?FantasyTeam
    {
        return $this->teams()->where('is_mine', true)->first();
    }
}
