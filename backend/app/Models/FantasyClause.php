<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FantasyClause extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'fantasy_team_player_id',
        'clause_value',
        'opportunity_score',
        'is_recommended',
        'analysis',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'opportunity_score' => 'decimal:2',
            'is_recommended' => 'boolean',
            'analysis' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    public function teamPlayer(): BelongsTo
    {
        return $this->belongsTo(FantasyTeamPlayer::class, 'fantasy_team_player_id');
    }
}
