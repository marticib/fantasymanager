<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fantasy_team_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fantasy_team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fantasy_player_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('clause_value')->nullable();
            $table->timestamp('clause_locked_until')->nullable()->comment('blindatge expiry, if applicable');
            $table->boolean('is_locked')->default(false)->comment('blindatge actiu');
            $table->boolean('is_starter')->nullable();
            $table->bigInteger('purchase_price')->nullable();
            $table->timestamp('acquired_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['fantasy_team_id', 'fantasy_player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fantasy_team_players');
    }
};
