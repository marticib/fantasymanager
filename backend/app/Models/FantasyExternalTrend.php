<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FantasyExternalTrend extends Model
{
    use HasFactory;

    protected $fillable = [
        'fantasy_player_id',
        'source',
        'external_id',
        'match_confidence',
        'value_now',
        'value_1d',
        'value_3d',
        'value_7d',
        'value_14d',
        'value_30d',
        'pct_1d',
        'pct_2d',
        'pct_3d',
        'pct_7d',
        'pct_14d',
        'pct_30d',
        'trend_days',
        'decelerating',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'pct_1d' => 'decimal:2',
            'pct_2d' => 'decimal:2',
            'pct_3d' => 'decimal:2',
            'pct_7d' => 'decimal:2',
            'pct_14d' => 'decimal:2',
            'pct_30d' => 'decimal:2',
            'decelerating' => 'boolean',
            'fetched_at' => 'datetime',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(FantasyPlayer::class, 'fantasy_player_id');
    }
}
