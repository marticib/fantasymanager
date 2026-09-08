<?php

namespace App\Services\FantasyApi\DTOs;

/**
 * Shared defensive-parsing helpers for adapting LaLiga Fantasy's undocumented
 * JSON payloads. We do not trust any single key name to be stable across
 * seasons, so every accessor tries a list of plausible candidates and falls
 * back to null rather than throwing. Callers should treat every DTO field as
 * "best effort" and always keep the original payload (see $raw on each DTO)
 * so nothing is lost if a field we didn't anticipate turns out to matter.
 */
abstract class BaseFantasyDTO
{
    protected static function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = data_get($data, $key);

            // A candidate key sometimes turns out to hold an object/array in
            // LaLiga's payload (e.g. a translations map instead of a plain
            // string) rather than the scalar we expected — skip it instead
            // of blowing up on (string) $array, and try the next candidate.
            if (is_array($value)) {
                continue;
            }

            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    protected static function firstInt(array $data, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = data_get($data, $key);
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    protected static function firstFloat(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = data_get($data, $key);
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    protected static function firstBool(array $data, array $keys): ?bool
    {
        foreach ($keys as $key) {
            $value = data_get($data, $key);
            if (is_bool($value)) {
                return $value;
            }
        }

        return null;
    }
}
