<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable record of what an economic engine (PlayerDecisionEngine,
 * MarketBuyAnalysisService, ClauseEconomicAnalysisService — never a second
 * copy of any of them) actually recommended at a point in time, so it can be
 * graded later against what really happened. `payload` freezes the full
 * decision context (scores, projections, dataQuality, reason) exactly as
 * computed that day — re-running today's algorithm must never change what a
 * past snapshot says it recommended.
 */
class FantasyDecisionSnapshot extends Model
{
    use HasFactory;

    public const TYPE_ROSTER = 'ROSTER';

    public const TYPE_MARKET_BUY = 'MARKET_BUY';

    public const TYPE_RIVAL_CLAUSE = 'RIVAL_CLAUSE';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_EVALUATED = 'EVALUATED';

    public const STATUS_INSUFFICIENT_DATA = 'INSUFFICIENT_DATA';

    public const STATUS_NOT_EVALUABLE = 'NOT_EVALUABLE';

    protected $fillable = [
        'fantasy_account_id',
        'fantasy_player_id',
        'fantasy_league_id',
        'decision_type',
        'action',
        'current_market_value',
        'reference_value',
        'projected_value',
        'main_score',
        'confidence',
        'horizon_days',
        'snapshot_date',
        'payload',
        'algorithm_version',
        'status',
        'outcome',
        'generated_at',
        'evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'outcome' => 'array',
            // Explicit Y-m-d format — the plain 'date' cast still serializes
            // with a time component on write (Laravel quirk), which would
            // silently break the account/player/action/snapshot_date lookup
            // this row's whole upsert-once-per-day guarantee depends on.
            'snapshot_date' => 'date:Y-m-d',
            'generated_at' => 'datetime',
            'evaluated_at' => 'datetime',
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

    public function league(): BelongsTo
    {
        return $this->belongsTo(FantasyLeague::class, 'fantasy_league_id');
    }
}
