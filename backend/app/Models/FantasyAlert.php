<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FantasyAlert extends Model
{
    use HasFactory;

    public const TYPE_BUY_OPPORTUNITY = 'BUY_OPPORTUNITY';

    public const TYPE_PRICE_DROP = 'PRICE_DROP';

    public const TYPE_CLAUSE_OPPORTUNITY = 'CLAUSE_OPPORTUNITY';

    public const TYPE_OFFER_RECEIVED = 'OFFER_RECEIVED';

    public const TYPE_MARKET_AVAILABILITY = 'MARKET_AVAILABILITY';

    public const TYPE_RISK_OF_LOSS = 'RISK_OF_LOSS';

    protected $fillable = [
        'fantasy_account_id',
        'type',
        'title',
        'message',
        'fantasy_player_id',
        'fantasy_recommendation_id',
        'severity',
        'channel',
        'read_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FantasyAccount::class, 'fantasy_account_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(FantasyPlayer::class, 'fantasy_player_id');
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(FantasyRecommendation::class, 'fantasy_recommendation_id');
    }
}
