<?php

namespace Tests\Unit\Services\FantasyApi\DTOs;

use App\Services\FantasyApi\DTOs\FantasyPlayerDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * LaLiga's /lineup endpoint returns `position` as a full Spanish label
 * ("Portero", "Defensa", ...) rather than a slug, confirmed against a live
 * response — this locks in that the DTO still normalizes to GK/DF/MF/FW
 * (which the rest of the app — Fantasy Score ceilings, market filters, squad
 * analysis — assumes), preferring the more stable numeric `positionId`.
 */
class FantasyPlayerDTOTest extends TestCase
{
    /**
     * @return array<string, array{0: array, 1: string}>
     */
    public static function positionShapes(): array
    {
        return [
            'numeric positionId' => [['positionId' => 1], 'GK'],
            'spanish label from /lineup' => [['position' => 'Portero'], 'GK'],
            'spanish label, defender' => [['position' => 'Defensa'], 'DF'],
            'spanish label, midfielder' => [['position' => 'Centrocampista'], 'MF'],
            'spanish label, forward' => [['position' => 'Delantero'], 'FW'],
            'short slug' => [['position' => 'DL'], 'FW'],
            'positionId wins over a conflicting position label' => [['positionId' => 2, 'position' => 'Delantero'], 'DF'],
        ];
    }

    #[DataProvider('positionShapes')]
    public function test_normalizes_position_to_a_stable_code(array $data, string $expected): void
    {
        $dto = FantasyPlayerDTO::fromArray(array_merge(['id' => '1', 'name' => 'Test'], $data));

        $this->assertSame($expected, $dto->position);
    }
}
