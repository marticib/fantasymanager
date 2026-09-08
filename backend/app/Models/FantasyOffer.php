<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FantasyOffer extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_ACCEPTED = 'ACCEPTED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_EXPIRED = 'EXPIRED';

    public const STATUS_WITHDRAWN = 'WITHDRAWN';

    protected $fillable = [
        'fantasy_league_id',
        'fantasy_player_id',
        'offering_team_id',
        'receiving_team_id',
        'amount',
        'status',
        'type',
        'expires_at',
        'external_id',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
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

    public function offeringTeam(): BelongsTo
    {
        return $this->belongsTo(FantasyTeam::class, 'offering_team_id');
    }

    public function receivingTeam(): BelongsTo
    {
        return $this->belongsTo(FantasyTeam::class, 'receiving_team_id');
    }
}
