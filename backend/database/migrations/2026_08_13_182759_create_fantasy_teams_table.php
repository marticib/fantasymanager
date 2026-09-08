<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fantasy_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fantasy_league_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->comment('LaLiga Fantasy team id');
            $table->string('name');
            $table->string('manager_name')->nullable();
            $table->boolean('is_mine')->default(false);
            $table->bigInteger('money')->nullable()->comment('available cash / saldo, in euros');
            $table->bigInteger('team_value')->nullable()->comment('cached total squad value');
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['fantasy_league_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fantasy_teams');
    }
};
