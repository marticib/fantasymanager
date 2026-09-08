<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fantasy_clubs', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique()->comment('LaLiga teamId, referenced by fantasy_players.club_external_id');
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->string('badge_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fantasy_clubs');
    }
};
