<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FantasyPlayerSnapshot extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'fantasy_player_id',
        'market_value',
        'points',
        'average_points',
        'owner_team_id',
        'clause_value',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'average_points' => 'decimal:2',
            'captured_at' => 'datetime',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(FantasyPlayer::class, 'fantasy_player_id');
    }

    public function ownerTeam(): BelongsTo
    {
        return $this->belongsTo(FantasyTeam::class, 'owner_team_id');
    }
}
