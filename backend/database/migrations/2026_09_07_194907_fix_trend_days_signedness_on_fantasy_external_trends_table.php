<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The source's `data-tendencia` value can be negative (sign = direction:
     * "-13" means 13 days trending down, not an error) — confirmed live.
     * `unsignedInteger` was wrong; on Postgres it's silently just a plain
     * integer anyway (no native unsigned type), so no data was lost, but the
     * column's declared type should say what the data actually is. This
     * table is fully repopulated by the next `fantasy:sync-external-trends`
     * run, so drop+recreate instead of a doctrine/dbal-dependent ->change().
     */
    public function up(): void
    {
        Schema::table('fantasy_external_trends', function (Blueprint $table) {
            $table->dropColumn('trend_days');
        });

        Schema::table('fantasy_external_trends', function (Blueprint $table) {
            $table->integer('trend_days')->nullable()->after('pct_30d')
                ->comment('signed: negative = N days trending down, positive = N days trending up');
        });
    }

    public function down(): void
    {
        Schema::table('fantasy_external_trends', function (Blueprint $table) {
            $table->dropColumn('trend_days');
        });

        Schema::table('fantasy_external_trends', function (Blueprint $table) {
            $table->unsignedInteger('trend_days')->nullable()->after('pct_30d');
        });
    }
};
