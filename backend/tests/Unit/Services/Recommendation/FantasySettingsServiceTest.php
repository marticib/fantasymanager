<?php

namespace Tests\Unit\Services\Recommendation;

use App\Models\FantasyAccount;
use App\Models\User;
use App\Services\Recommendation\FantasySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FantasySettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_falls_back_to_config_defaults_when_no_rows_exist(): void
    {
        $rules = (new FantasySettingsService)->rules();

        $this->assertSame(config('fantasy.rules_defaults')['minimum_cash_reserve'], $rules['minimum_cash_reserve']);
    }

    public function test_per_account_override_wins_over_global_default(): void
    {
        $account = FantasyAccount::create(['user_id' => User::factory()->create()->id]);
        $settings = new FantasySettingsService;

        $settings->set('minimum_cash_reserve', 5_000_000, $account);

        $this->assertSame(5_000_000, $settings->rules($account)['minimum_cash_reserve']);
        $this->assertSame(
            config('fantasy.rules_defaults')['minimum_cash_reserve'],
            $settings->rules(null)['minimum_cash_reserve'],
        );
    }

    public function test_available_capital_respects_the_minimum_cash_reserve(): void
    {
        $account = FantasyAccount::create(['user_id' => User::factory()->create()->id]);
        $settings = new FantasySettingsService;
        $settings->set('minimum_cash_reserve', 3_000_000, $account);

        $cash = 8_000_000;
        $availableCapital = max(0, $cash - (int) $settings->rules($account)['minimum_cash_reserve']);

        $this->assertSame(5_000_000, $availableCapital);
    }

    public function test_available_capital_never_goes_negative_when_cash_is_below_reserve(): void
    {
        $account = FantasyAccount::create(['user_id' => User::factory()->create()->id]);
        $settings = new FantasySettingsService;
        $settings->set('minimum_cash_reserve', 3_000_000, $account);

        $cash = 500_000;
        $availableCapital = max(0, $cash - (int) $settings->rules($account)['minimum_cash_reserve']);

        $this->assertSame(0, $availableCapital);
    }
}
