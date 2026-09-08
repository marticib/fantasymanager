<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * LaLiga's own API never exposes historical market value — confirmed
     * live against /player/{id}/league/{id}, /players and /teams/{id}/lineup
     * (only a current `marketValue`, never a series). This table holds a
     * supplementary, UNOFFICIAL trend read scraped from futbolfantasy.com's
     * public market analytics page, which already computes 1/2/3/7/14/30-day
     * deltas from its own long-running history. It is informational only —
     * matched to our fantasy_players by name (a different site, different
     * player ids), never authoritative, and never fed into FantasyScoreService
     * or the recommendation engine (see FutbolFantasySyncService docblock).
     */
    public function up(): void
    {
        Schema::create('fantasy_external_trends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fantasy_player_id')->constrained()->cascadeOnDelete();
            $table->string('source')->default('futbolfantasy');
            $table->string('external_id')->nullable()->comment('the source site\'s own player id, for debugging only');
            $table->string('match_confidence')->comment('exact, club_disambiguated — how sure the name-matching was');
            $table->bigInteger('value_now')->nullable();
            $table->decimal('pct_1d', 6, 2)->nullable();
            $table->decimal('pct_2d', 6, 2)->nullable();
            $table->decimal('pct_3d', 6, 2)->nullable();
            $table->decimal('pct_7d', 6, 2)->nullable();
            $table->decimal('pct_14d', 6, 2)->nullable();
            $table->decimal('pct_30d', 6, 2)->nullable();
            $table->unsignedInteger('trend_days')->nullable()->comment('consecutive days moving in the current direction, per the source');
            $table->boolean('decelerating')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['fantasy_player_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fantasy_external_trends');
    }
};
