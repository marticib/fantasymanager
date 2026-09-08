<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fantasy_player_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fantasy_player_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('market_value')->nullable();
            $table->unsignedInteger('points')->nullable();
            $table->decimal('average_points', 6, 2)->nullable();
            $table->foreignId('owner_team_id')->nullable()->constrained('fantasy_teams')->nullOnDelete();
            $table->bigInteger('clause_value')->nullable();
            $table->timestamp('captured_at');

            $table->index(['fantasy_player_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fantasy_player_snapshots');
    }
};
