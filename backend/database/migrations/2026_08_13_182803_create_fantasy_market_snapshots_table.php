<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fantasy_market_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fantasy_league_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fantasy_player_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('market_value')->nullable();
            $table->bigInteger('asking_price')->nullable();
            $table->boolean('is_on_market')->default(true);
            $table->timestamp('captured_at');

            $table->index(['fantasy_league_id', 'fantasy_player_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fantasy_market_snapshots');
    }
};
