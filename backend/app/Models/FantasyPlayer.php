<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FantasyPlayer extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_id',
        'name',
        'nickname',
        'club_name',
        'club_external_id',
        'position',
        'image_url',
        'status',
        'points',
        'average_points',
        'market_value',
        'previous_market_value',
        'raw_payload',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'average_points' => 'decimal:2',
            'raw_payload' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(FantasyPlayerSnapshot::class)->orderBy('captured_at');
    }

    /**
     * Eager-load constraint for list screens:
     * `->with(['player.snapshots' => FantasyPlayer::valueSnapshotsConstraint()])`
     * — one query for every row's history instead of several per player.
     */
    public static function valueSnapshotsConstraint(): Closure
    {
        return fn ($query) => $query
            ->whereNotNull('market_value')
            ->select(['id', 'fantasy_player_id', 'market_value', 'captured_at']);
    }

    /**
     * Market-value snapshots, oldest first, optionally only those captured
     * since $since. Served from the eager-loaded relation when the caller
     * preloaded it (see valueSnapshotsConstraint()), otherwise queried.
     *
     * @return Collection<int, FantasyPlayerSnapshot>
     */
    public function valueSnapshots(?CarbonInterface $since = null): Collection
    {
        if ($this->relationLoaded('snapshots')) {
            $snapshots = $this->snapshots->whereNotNull('market_value');

            if ($since) {
                $snapshots = $snapshots->filter(fn (FantasyPlayerSnapshot $s) => $s->captured_at->greaterThanOrEqualTo($since));
            }

            return $snapshots->values();
        }

        $query = $this->snapshots()->whereNotNull('market_value');

        if ($since) {
            $query->where('captured_at', '>=', $since);
        }

        return $query->get(['market_value', 'captured_at']);
    }

    public function teamPlayers(): HasMany
    {
        return $this->hasMany(FantasyTeamPlayer::class);
    }

    public function marketListings(): HasMany
    {
        return $this->hasMany(FantasyMarketPlayer::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(FantasyRecommendation::class);
    }

    public function latestSnapshot(): ?FantasyPlayerSnapshot
    {
        return $this->snapshots()->latest('captured_at')->first();
    }

    /**
     * Current owner across all synced leagues, if any (null = free agent / on the market).
     */
    public function currentOwner(): ?FantasyTeam
    {
        $teamPlayer = $this->teamPlayers()->latest('id')->first();

        return $teamPlayer?->team;
    }

    /**
     * Real per-gameweek points straight from LaLiga's own payload, normalized
     * across the two shapes different endpoints return it in — confirmed
     * live, not an edge case: a market/catalog sync leaves `weekPoints` as
     * `[{weekNumber, points}]`, while a roster/lineup sync (i.e. every
     * player currently on a team) instead leaves `weekPoints` as a bare
     * scalar (just the most recent week) and carries the real breakdown
     * under `lastStats` as `[{weekNumber, totalPoints, ...}]` instead. Which
     * shape a given player has depends on which endpoint last synced it, not
     * on the player, so both must be read — reading `weekPoints` alone
     * silently sees "no data" for every owned player.
     *
     * @return array<int, int> weekNumber => points
     */
    public function weekPointsBreakdown(): array
    {
        $weekPoints = collect($this->raw_payload['weekPoints'] ?? [])
            ->filter(fn ($w) => is_array($w) && isset($w['weekNumber'], $w['points']))
            ->mapWithKeys(fn ($w) => [(int) $w['weekNumber'] => (int) $w['points']]);

        if ($weekPoints->isNotEmpty()) {
            return $weekPoints->all();
        }

        return collect($this->raw_payload['lastStats'] ?? [])
            ->filter(fn ($w) => is_array($w) && isset($w['weekNumber'], $w['totalPoints']))
            ->mapWithKeys(fn ($w) => [(int) $w['weekNumber'] => (int) $w['totalPoints']])
            ->all();
    }
}
