<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FantasyTeamPlayer extends Model
{
    use HasFactory;

    protected $fillable = [
        'fantasy_team_id',
        'fantasy_player_id',
        'player_team_id',
        'clause_value',
        'clause_locked_until',
        'is_locked',
        'is_starter',
        'purchase_price',
        'acquired_at',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'clause_locked_until' => 'datetime',
            'is_locked' => 'boolean',
            'is_starter' => 'boolean',
            'acquired_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(FantasyTeam::class, 'fantasy_team_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(FantasyPlayer::class, 'fantasy_player_id');
    }

    public function clauseHistory(): HasMany
    {
        return $this->hasMany(FantasyClause::class);
    }
}
