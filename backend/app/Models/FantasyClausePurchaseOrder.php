<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user-placed order to automatically pay a rival's buyout clause the
 * moment it unlocks — the app's first automated *write* action against the
 * real LaLiga API. See ClausePurchaseOrderService for the state machine.
 */
class FantasyClausePurchaseOrder extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_NEEDS_CONFIRMATION = 'NEEDS_CONFIRMATION';

    public const STATUS_EXECUTED = 'EXECUTED';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'fantasy_account_id',
        'fantasy_league_id',
        'fantasy_player_id',
        'target_team_id',
        'player_team_id',
        'clause_value_at_order',
        'pending_confirmation_clause_value',
        'executed_clause_value',
        'status',
        'error_message',
        'last_checked_at',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_checked_at' => 'datetime',
            'executed_at' => 'datetime',
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

    public function targetTeam(): BelongsTo
    {
        return $this->belongsTo(FantasyTeam::class, 'target_team_id');
    }
}
