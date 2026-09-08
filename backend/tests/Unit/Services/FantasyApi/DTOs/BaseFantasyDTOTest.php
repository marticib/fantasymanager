<?php

namespace Tests\Unit\Services\FantasyApi\DTOs;

use App\Services\FantasyApi\DTOs\FantasyLeagueDTO;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for a real failure hit against the live API: a
 * candidate key (e.g. "name") can hold an object/array in LaLiga's payload
 * instead of the plain string every DTO assumed, which used to blow up with
 * "Array to string conversion" instead of just skipping to the next
 * candidate (see BaseFantasyDTO::firstString).
 */
class BaseFantasyDTOTest extends TestCase
{
    public function test_an_array_value_under_a_candidate_key_is_skipped_instead_of_crashing(): void
    {
        $dto = FantasyLeagueDTO::fromArray([
            'id' => 42,
            'name' => ['es' => 'La meva lliga', 'en' => 'My league'],
            'leagueName' => 'Fallback Name',
            'mode' => ['slug' => 'classic'],
            'teamCount' => 12,
        ]);

        $this->assertSame('42', $dto->externalId);
        $this->assertSame('Fallback Name', $dto->name);
        $this->assertNull($dto->mode);
        $this->assertSame(12, $dto->teamCount);
    }

    public function test_all_candidates_being_arrays_resolves_to_null_not_a_crash(): void
    {
        $dto = FantasyLeagueDTO::fromArray([
            'id' => 1,
            'name' => ['es' => 'Nom'],
            'leagueName' => ['es' => 'Nom'],
        ]);

        $this->assertNull($dto->name);
    }
}
