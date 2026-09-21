<?php

namespace Tests\Unit\Services\Recommendation;

use App\Models\FantasyExternalTrend;
use App\Services\Recommendation\PlayerValueTrendCalculator;
use Tests\TestCase;

class PlayerValueTrendCalculatorExternalTest extends TestCase
{
    private function trend(array $values): FantasyExternalTrend
    {
        return new FantasyExternalTrend($values);
    }

    public function test_it_returns_futbolfantasys_own_1_3_7_day_values(): void
    {
        $result = (new PlayerValueTrendCalculator)->externalHistoricalValues(
            $this->trend(['value_1d' => 9_900_000, 'value_3d' => 9_700_000, 'value_7d' => 9_000_000]),
            10_000_000,
        );

        $this->assertSame([9_900_000.0, 9_700_000.0, 9_000_000.0], $result);
    }

    public function test_a_missing_window_is_null_never_zero(): void
    {
        $result = (new PlayerValueTrendCalculator)->externalHistoricalValues($this->trend(['value_1d' => 9_900_000]), 10_000_000);

        $this->assertSame([9_900_000.0, null, null], $result);
    }

    public function test_an_implausible_window_is_dropped_like_in_the_own_first_path(): void
    {
        $result = (new PlayerValueTrendCalculator)->externalHistoricalValues(
            $this->trend(['value_1d' => 9_900_000, 'value_3d' => 1_000_000, 'value_7d' => 0]),
            10_000_000,
        );

        $this->assertSame([9_900_000.0, null, null], $result);
    }
}
