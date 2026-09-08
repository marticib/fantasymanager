<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FantasyStanding extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'fantasy_league_id',
        'fantasy_team_id',
        'position',
        'points',
        'matchday',
        'raw_payload',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    public function league(): BelongsTo
    {
        return $this->belongsTo(FantasyLeague::class, 'fantasy_league_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(FantasyTeam::class, 'fantasy_team_id');
    }
}
