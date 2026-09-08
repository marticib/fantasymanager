<?php

namespace App\Services\Recommendation;

use App\Models\FantasyAccount;
use App\Models\FantasySetting;
use Illuminate\Support\Facades\Cache;

/**
 * Every threshold the recommendation engine uses is a row in fantasy_settings
 * rather than a constant, so the Settings screen can tune it without a
 * deploy. A row with fantasy_account_id = null is the global default (seeded
 * from config/fantasy.php by `php artisan db:seed --class=FantasySettingsSeeder`);
 * a row scoped to an account overrides it for that account only.
 */
class FantasySettingsService
{
    private const CACHE_TTL_SECONDS = 60;

    public function rules(?FantasyAccount $account = null): array
    {
        return array_merge(
            config('fantasy.rules_defaults'),
            $this->overridesFor($account, array_keys(config('fantasy.rules_defaults'))),
        );
    }

    public function scoreWeights(?FantasyAccount $account = null): array
    {
        $weights = array_merge(
            config('fantasy.score_weights_defaults'),
            $this->overridesFor($account, array_keys(config('fantasy.score_weights_defaults'))),
        );

        $total = array_sum($weights) ?: 1;

        // Normalize so custom weights always sum to 100, however the user edited them.
        return array_map(fn ($w) => $w * 100 / $total, $weights);
    }

    public function get(string $key, mixed $default, ?FantasyAccount $account = null): mixed
    {
        $value = $this->allSettingsFor($account)[$key] ?? null;

        return $value ?? $default;
    }

    public function set(string $key, mixed $value, ?FantasyAccount $account = null, ?string $description = null): FantasySetting
    {
        $setting = FantasySetting::updateOrCreate(
            ['fantasy_account_id' => $account?->id, 'key' => $key],
            ['value' => $value, 'description' => $description],
        );

        Cache::forget($this->cacheKey($account));

        return $setting;
    }

    private function overridesFor(?FantasyAccount $account, array $keys): array
    {
        $all = $this->allSettingsFor($account);

        return array_intersect_key($all, array_flip($keys));
    }

    private function allSettingsFor(?FantasyAccount $account): array
    {
        return Cache::remember($this->cacheKey($account), self::CACHE_TTL_SECONDS, function () use ($account) {
            return FantasySetting::query()
                ->where('fantasy_account_id', $account?->id)
                ->pluck('value', 'key')
                ->toArray();
        });
    }

    private function cacheKey(?FantasyAccount $account): string
    {
        return 'fantasy_settings:'.($account?->id ?? 'global');
    }
}
