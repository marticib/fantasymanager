<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fantasy_standings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fantasy_league_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fantasy_team_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->integer('points')->default(0);
            $table->unsignedInteger('matchday')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('captured_at');

            $table->index(['fantasy_league_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fantasy_standings');
    }
};
