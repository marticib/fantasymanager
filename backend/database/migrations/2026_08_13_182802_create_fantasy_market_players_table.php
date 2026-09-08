<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fantasy_market_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fantasy_league_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fantasy_player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_team_id')->nullable()->constrained('fantasy_teams')->nullOnDelete();
            $table->bigInteger('market_value')->nullable();
            $table->bigInteger('asking_price')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_on_market')->default(true);
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['fantasy_league_id', 'fantasy_player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fantasy_market_players');
    }
};
