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

    public const TYPE_AUCTION_ENDED = 'AUCTION_ENDED';

    public const TYPE_CLAUSE_ORDER = 'CLAUSE_ORDER';

    public const TYPE_SESSION_EXPIRED = 'SESSION_EXPIRED';

    public const SEVERITY_INFO = 'INFO';

    public const SEVERITY_WARNING = 'WARNING';

    public const SEVERITY_CRITICAL = 'CRITICAL';

    protected $fillable = [
        'fantasy_account_id',
        'type',
        'title',
        'message',
        'link',
        'dedupe_key',
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
