<?php

namespace App\Services\FantasyApi\DTOs;

/**
 * One player's spot in a team's lineup response — the player itself plus
 * roster-context info (starter vs bench) that doesn't belong on
 * FantasyPlayerDTO, since a player isn't inherently a starter, only within
 * one team's current formation. Null when the source endpoint doesn't
 * expose a formation at all (the league-scoped roster endpoint used for
 * rival teams returns a flat player list with no starter/bench split).
 */
class FantasyRosterEntryDTO
{
    public function __construct(
        public readonly FantasyPlayerDTO $player,
        public readonly ?bool $isStarter,
        public readonly ?string $clauseLockedUntil = null,
        public readonly ?bool $isShielded = null,
    ) {}
}
