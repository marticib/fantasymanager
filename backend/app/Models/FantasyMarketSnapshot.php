<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FantasyMarketSnapshot extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'fantasy_league_id',
        'fantasy_player_id',
        'market_value',
        'asking_price',
        'is_on_market',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'is_on_market' => 'boolean',
            'captured_at' => 'datetime',
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
}
