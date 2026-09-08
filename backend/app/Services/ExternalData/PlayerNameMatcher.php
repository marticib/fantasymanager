<?php

namespace App\Services\ExternalData;

use App\Models\FantasyPlayer;
use Illuminate\Support\Collection;

/**
 * futbolfantasy.com has its own player ids — matching back to our
 * fantasy_players is name-based, since that's the only thing both sides
 * share. Deliberately conservative: an ambiguous name with no club
 * agreement is left unmatched rather than risk attaching one player's
 * trend data to another.
 */
class PlayerNameMatcher
{
    /**
     * Indexes each player under their full normalized name/nickname AND
     * under just the last word of it ("Javi Hernández" -> also "hernandez")
     * — our own stored names are just as often "Firstname Surname" as a bare
     * nickname, so the surname-only key is what lets a scraped "Javier
     * Hernández" suffix-match down to it (see match()).
     *
     * @param  Collection<int, FantasyPlayer>  $players
     * @return array<string, FantasyPlayer[]>
     */
    public function buildIndex(Collection $players): array
    {
        $index = [];

        foreach ($players as $player) {
            foreach (array_filter([$player->name, $player->nickname]) as $candidate) {
                $normalized = self::normalize($candidate);

                if ($normalized === '') {
                    continue;
                }

                $index[$normalized][] = $player;

                $tokens = explode(' ', $normalized);
                if (count($tokens) > 1) {
                    $index[end($tokens)][] = $player;
                }
            }
        }

        return $index;
    }

    /**
     * Our own player names are frequently just the short nickname LaLiga
     * itself uses ("Sivera"), while futbolfantasy publishes full names
     * ("Antonio Sivera") — confirmed live, not an edge case (roughly 3 in 4
     * rows on a real fetch). A plain full-name match alone left most of the
     * page unmatched, so this tries the full name first, then progressively
     * shorter trailing-word suffixes ("sivera" alone), using the club to
     * disambiguate whenever a suffix matches more than one of our players.
     * It stops at the first suffix length with any hit — a shorter,
     * unresolved-ambiguous suffix is more likely to be wrong, not less.
     *
     * @param  array<string, FantasyPlayer[]>  $index
     * @return array{player: ?FantasyPlayer, confidence: ?string}
     */
    public function match(array $index, string $rawName, ?string $clubName): array
    {
        $tokens = array_values(array_filter(explode(' ', self::normalize($rawName))));

        if (empty($tokens)) {
            return ['player' => null, 'confidence' => null];
        }

        for ($i = 0; $i < count($tokens); $i++) {
            $key = implode(' ', array_slice($tokens, $i));
            $candidates = collect($index[$key] ?? [])->unique('id')->values();

            if ($candidates->isEmpty()) {
                continue;
            }

            if ($candidates->count() === 1) {
                return ['player' => $candidates->first(), 'confidence' => $i === 0 ? 'exact' : 'suffix'];
            }

            if ($clubName) {
                $clubKey = self::normalize($clubName);
                $filtered = $candidates->filter(function (FantasyPlayer $p) use ($clubKey) {
                    $playerClubKey = self::normalize((string) $p->club_name);

                    return $playerClubKey !== '' && (str_contains($playerClubKey, $clubKey) || str_contains($clubKey, $playerClubKey));
                });

                if ($filtered->count() === 1) {
                    return ['player' => $filtered->first(), 'confidence' => 'club_disambiguated'];
                }
            }

            // Ambiguous at this suffix length and the club didn't resolve it — stop here
            // rather than fall through to an even shorter, more ambiguity-prone suffix.
            return ['player' => null, 'confidence' => null];
        }

        return ['player' => null, 'confidence' => null];
    }

    /**
     * Lowercase, strips acute/diaeresis/circumflex accents but keeps ñ (it's
     * its own letter in Spanish, not an accented n) — matches the apparent
     * convention of futbolfantasy's own `data-nombre` attribute (confirmed
     * live: "joaquin muñoz", "iñigo arguibide" — á/í stripped, ñ kept).
     */
    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ç' => 'c',
        ]);
        $value = preg_replace('/[^a-z0-9ñ ]/u', '', $value) ?? '';
        $value = preg_replace('/\s+/', ' ', $value) ?? '';

        return trim($value);
    }
}
