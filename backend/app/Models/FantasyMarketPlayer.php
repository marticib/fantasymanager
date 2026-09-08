<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FantasyMarketPlayer extends Model
{
    use HasFactory;

    protected $fillable = [
        'fantasy_league_id',
        'fantasy_player_id',
        'seller_team_id',
        'market_value',
        'asking_price',
        'expires_at',
        'is_on_market',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'is_on_market' => 'boolean',
            'raw_payload' => 'array',
        ];
    }

    public function league(): BelongsTo
    {
        return $this->belongsTo(FantasyLeague::class, 'fantasy_league_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(FantasyPlayer::class, 'fantasy_player_id');
    }

    public function sellerTeam(): BelongsTo
    {
        return $this->belongsTo(FantasyTeam::class, 'seller_team_id');
    }
}
