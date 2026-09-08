<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FantasyTransaction extends Model
{
    use HasFactory;

    public const TYPE_BUY = 'BUY';

    public const TYPE_SELL = 'SELL';

    public const TYPE_CLAUSE_PAYMENT = 'CLAUSE_PAYMENT';

    public const TYPE_OFFER_ACCEPTED = 'OFFER_ACCEPTED';

    protected $fillable = [
        'fantasy_account_id',
        'fantasy_league_id',
        'fantasy_player_id',
        'type',
        'amount',
        'from_team_id',
        'to_team_id',
        'occurred_at',
        'external_id',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'raw_payload' => 'array',
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

    public function fromTeam(): BelongsTo
    {
        return $this->belongsTo(FantasyTeam::class, 'from_team_id');
    }

    public function toTeam(): BelongsTo
    {
        return $this->belongsTo(FantasyTeam::class, 'to_team_id');
    }
}
