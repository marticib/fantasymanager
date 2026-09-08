<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FantasyTeam extends Model
{
    use HasFactory;

    protected $fillable = [
        'fantasy_league_id',
        'external_id',
        'name',
        'manager_name',
        'is_mine',
        'money',
        'team_value',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'is_mine' => 'boolean',
            'raw_payload' => 'array',
        ];
    }

    public function league(): BelongsTo
    {
        return $this->belongsTo(FantasyLeague::class, 'fantasy_league_id');
    }

    public function teamPlayers(): HasMany
    {
        return $this->hasMany(FantasyTeamPlayer::class);
    }

    public function players(): BelongsToMany
    {
        return $this->belongsToMany(FantasyPlayer::class, 'fantasy_team_players')
            ->withPivot(['clause_value', 'clause_locked_until', 'is_locked', 'is_starter', 'purchase_price', 'acquired_at'])
            ->withTimestamps();
    }
}
