<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FantasyRecommendation extends Model
{
    use HasFactory;

    public const ACTION_BUY = 'BUY';

    public const ACTION_SELL = 'SELL';

    public const ACTION_HOLD = 'HOLD';

    public const ACTION_MARKET_LIST = 'MARKET_LIST';

    public const ACTION_RAISE_BID = 'RAISE_BID';

    public const ACTION_WITHDRAW_BID = 'WITHDRAW_BID';

    public const ACTION_PAY_CLAUSE = 'PAY_CLAUSE';

    public const ACTION_DO_NOT_PAY_CLAUSE = 'DO_NOT_PAY_CLAUSE';

    public const ACTION_LOCK_CLAUSE = 'LOCK_CLAUSE';

    public const ACTION_UNLOCK_CLAUSE = 'UNLOCK_CLAUSE';

    public const PRIORITY_CRITICAL = 'CRITICAL';

    public const PRIORITY_HIGH = 'HIGH';

    public const PRIORITY_MEDIUM = 'MEDIUM';

    public const PRIORITY_LOW = 'LOW';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_EXPIRED = 'EXPIRED';

    public const STATUS_DISMISSED = 'DISMISSED';

    public const STATUS_ACTED_ON = 'ACTED_ON';

    protected $fillable = [
        'fantasy_account_id',
        'fantasy_league_id',
        'fantasy_player_id',
        'action',
        'priority',
        'confidence',
        'reason',
        'explanation',
        'financial_impact',
        'recommended_amount',
        'max_amount',
        'fantasy_score',
        'status',
        'expires_at',
        'generated_at',
        'outcome',
    ];

    protected function casts(): array
    {
        return [
            'explanation' => 'array',
            'fantasy_score' => 'decimal:2',
            'expires_at' => 'datetime',
            'generated_at' => 'datetime',
            'outcome' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FantasyAccount::class, 'fantasy_account_id');
    }

    public function league(): BelongsTo
    {
        return $this->belongsTo(FantasyLeague::class, 'fantasy_league_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(FantasyPlayer::class, 'fantasy_player_id');
    }

    public static function priorityWeight(string $priority): int
    {
        return match ($priority) {
            self::PRIORITY_CRITICAL => 4,
            self::PRIORITY_HIGH => 3,
            self::PRIORITY_MEDIUM => 2,
            self::PRIORITY_LOW => 1,
            default => 0,
        };
    }
}
