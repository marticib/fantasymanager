<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FantasyAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'fantasy_user_id',
        'nickname',
        'access_token',
        'refresh_token',
        'token_client_id',
        'token_expires_at',
        'active_league_id',
        'active_team_id',
        'last_synced_at',
        'current_matchday',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function leagues(): HasMany
    {
        return $this->hasMany(FantasyLeague::class);
    }

    public function activeLeague(): BelongsTo
    {
        return $this->belongsTo(FantasyLeague::class, 'active_league_id');
    }

    public function activeTeam(): BelongsTo
    {
        return $this->belongsTo(FantasyTeam::class, 'active_team_id');
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(FantasyRecommendation::class);
    }

    public function dailyReports(): HasMany
    {
        return $this->hasMany(FantasyDailyReport::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(FantasyAlert::class);
    }

    public function hasValidTokens(): bool
    {
        try {
            return filled($this->access_token) && filled($this->refresh_token);
        } catch (DecryptException) {
            // APP_KEY changed since these were encrypted (e.g. a stale
            // key:generate re-run) — the stored session is permanently
            // unrecoverable, exactly like having no tokens at all: treat it
            // as "please reconnect", never let the raw decrypt exception
            // surface as an uncaught crash from every place that reads it.
            return false;
        }
    }
}
