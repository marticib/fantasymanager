<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * futbolfantasy.com's rows also carry the absolute value at each day
     * offset (`data-valor1/3/7/14/30`), not just the percentage change —
     * captured now so the market table's 7-day sparkline has real points to
     * draw even before our own fantasy_player_snapshots history has
     * accumulated (see MarketController).
     */
    public function up(): void
    {
        Schema::table('fantasy_external_trends', function (Blueprint $table) {
            $table->bigInteger('value_1d')->nullable()->after('value_now');
            $table->bigInteger('value_3d')->nullable()->after('value_1d');
            $table->bigInteger('value_7d')->nullable()->after('value_3d');
            $table->bigInteger('value_14d')->nullable()->after('value_7d');
            $table->bigInteger('value_30d')->nullable()->after('value_14d');
        });
    }

    public function down(): void
    {
        Schema::table('fantasy_external_trends', function (Blueprint $table) {
            $table->dropColumn(['value_1d', 'value_3d', 'value_7d', 'value_14d', 'value_30d']);
        });
    }
};
