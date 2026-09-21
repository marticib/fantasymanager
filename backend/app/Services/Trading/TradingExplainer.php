<?php

namespace App\Services\Trading;

/**
 * Deterministic, template-based explanations for Trading rows and summaries —
 * every sentence is assembled from figures the engines already produced, in a
 * fixed wording, so the same input always reads the same (no LLM, no
 * free-form generation). Catalan, like the rest of the UI copy.
 */
final class TradingExplainer
{
    /** Human labels for `excludeReason` codes. */
    public const EXCLUDE_LABELS = [
        'NO_MATCH' => 'Sense coincidència a FútbolFantasy (no identificat o ambigu)',
        'INSUFFICIENT_FF_DATA' => 'Dades de FútbolFantasy insuficients',
        'SUSPECT_VALUE_MISMATCH' => 'El valor de FútbolFantasy no quadra amb el de LaLiga (coincidència sospitosa)',
        'NO_PRICE' => 'Sense preu fiable',
        'DO_NOT_CHASE' => 'No perseguir: la puja guanyadora estimada supera la MaxBid',
        'MAX_BID_TOO_LOW' => 'La MaxBid és inferior al que demana la subhasta',
        'CLAUSE_LOCKED' => 'Clàusula encara bloquejada',
        'CLAUSE_SHIELDED' => 'Clàusula blindada',
        'BELOW_MIN_PROFIT' => 'Benefici esperat per sota del mínim configurat',
        'LOW_CONFIDENCE' => 'Confiança massa baixa per recomanar-lo',
    ];

    public static function money(?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        return number_format($value, 0, ',', '.').' €';
    }

    public static function signedMoney(?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        return ($value > 0 ? '+' : '').self::money($value);
    }

    public static function percent(?float $fraction): string
    {
        if ($fraction === null) {
            return '—';
        }

        return ($fraction > 0 ? '+' : '').number_format($fraction * 100, 1, ',', '.').'%';
    }

    /**
     * One sentence for a lineup/plan row.
     *
     * @param  array<string, mixed>  $row  a TradingCandidateService row
     */
    public static function forRow(array $row, int $horizon, string $role): string
    {
        return match ($role) {
            'OWN' => self::ownLine($row, $horizon),
            'MARKET' => sprintf(
                'Puja recomanada %s (MaxBid %s). Benefici esperat a %dD: %s (ROI %s).',
                self::money($row['acquisitionCost']),
                self::money($row['maxBid']),
                $horizon,
                self::signedMoney($row['profit'][$horizon] ?? null),
                self::percent($row['roi'][$horizon] ?? null),
            ),
            'CLAUSE' => sprintf(
                'Clàusula de %s. Benefici esperat a %dD: %s (ROI %s).',
                self::money($row['acquisitionCost']),
                $horizon,
                self::signedMoney($row['profit'][$horizon] ?? null),
                self::percent($row['roi'][$horizon] ?? null),
            ),
            default => '',
        };
    }

    /** @param array<string, mixed> $row */
    private static function ownLine(array $row, int $horizon): string
    {
        $evolution = $row['evolution'][$horizon] ?? null;

        if ($evolution === null) {
            return 'Ja el tens (cost 0 €). Sense dades de FútbolFantasy per estimar-ne l\'evolució.';
        }

        return sprintf('Ja el tens (cost 0 €). Evolució esperada a %dD: %s.', $horizon, self::signedMoney($evolution));
    }

    public static function excludeLabel(?string $reason): ?string
    {
        return $reason === null ? null : (self::EXCLUDE_LABELS[$reason] ?? $reason);
    }
}
