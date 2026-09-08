<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fantasy_players', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique()->comment('LaLiga Fantasy player id');
            $table->string('name');
            $table->string('nickname')->nullable();
            $table->string('club_name')->nullable()->comment('real football club');
            $table->string('club_external_id')->nullable();
            $table->string('position')->nullable()->comment('GK, DF, MF, FW');
            $table->string('image_url')->nullable();
            $table->string('status')->nullable()->comment('ok, injured, doubtful, sanctioned, ...');
            $table->unsignedInteger('points')->default(0)->comment('season total points');
            $table->decimal('average_points', 6, 2)->default(0);
            $table->bigInteger('market_value')->nullable();
            $table->bigInteger('previous_market_value')->nullable()->comment('cached value from prior sync, for fast diffing');
            $table->json('raw_payload')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index('position');
            $table->index('club_external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fantasy_players');
    }
};
