<?php

namespace Tests\Unit\Services\ExternalData;

use App\Models\FantasyPlayer;
use App\Services\ExternalData\PlayerNameMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerNameMatcherTest extends TestCase
{
    use RefreshDatabase;

    private function player(string $name, string $club, ?string $nickname = null): FantasyPlayer
    {
        return FantasyPlayer::create([
            'external_id' => uniqid(),
            'name' => $name,
            'nickname' => $nickname ?? $name,
            'club_name' => $club,
        ]);
    }

    public function test_exact_full_name_match(): void
    {
        $player = $this->player('Antonio Sivera', 'Deportivo Alavés');
        $matcher = new PlayerNameMatcher;
        $index = $matcher->buildIndex(FantasyPlayer::all());

        $result = $matcher->match($index, 'Antonio Sivera', 'Alavés');

        $this->assertSame($player->id, $result['player']->id);
        $this->assertSame('exact', $result['confidence']);
    }

    public function test_surname_only_suffix_match_when_our_name_is_a_short_nickname(): void
    {
        $player = $this->player('Sivera', 'Deportivo Alavés');
        $matcher = new PlayerNameMatcher;
        $index = $matcher->buildIndex(FantasyPlayer::all());

        // futbolfantasy publishes the full name; ours only stores the surname.
        $result = $matcher->match($index, 'Antonio Sivera', 'Alavés');

        $this->assertSame($player->id, $result['player']->id);
        $this->assertSame('suffix', $result['confidence']);
    }

    public function test_surname_only_suffix_match_when_our_name_is_first_plus_last(): void
    {
        $player = $this->player('Javi Hernández', 'RCD Espanyol');
        $this->player('Juan Hernández', 'FC Barcelona');
        $matcher = new PlayerNameMatcher;
        $index = $matcher->buildIndex(FantasyPlayer::all());

        // Different first name on their side ("Javier" vs our "Javi"), same surname —
        // ambiguous by surname alone, resolved by the club.
        $result = $matcher->match($index, 'Javier Hernández', 'Espanyol');

        $this->assertSame($player->id, $result['player']->id);
        $this->assertSame('club_disambiguated', $result['confidence']);
    }

    public function test_ambiguous_surname_with_no_club_agreement_is_left_unmatched(): void
    {
        $this->player('Iker Muñoz', 'Sevilla FC');
        $this->player('Iker Muñoz', 'C.A. Osasuna');
        $matcher = new PlayerNameMatcher;
        $index = $matcher->buildIndex(FantasyPlayer::all());

        $result = $matcher->match($index, 'Joaquín Muñoz', 'Málaga CF');

        $this->assertNull($result['player']);
        $this->assertNull($result['confidence']);
    }

    public function test_unknown_player_is_left_unmatched(): void
    {
        $this->player('Someone Else', 'Real Madrid');
        $matcher = new PlayerNameMatcher;
        $index = $matcher->buildIndex(FantasyPlayer::all());

        $result = $matcher->match($index, 'Nobody Here', 'Elche CF');

        $this->assertNull($result['player']);
    }

    public function test_normalize_strips_acute_accents_but_keeps_the_letter_n_tilde(): void
    {
        // Matches futbolfantasy's own data-nombre convention, confirmed live:
        // "iñigo arguibide", "joaquin muñoz" — í/é/á stripped, ñ kept as its own letter.
        $this->assertSame('iñigo arguibide', PlayerNameMatcher::normalize('Íñigo Arguibide'));
        $this->assertSame('joaquin muñoz', PlayerNameMatcher::normalize('Joaquín Muñoz'));
    }
}
