<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fantasy_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fantasy_league_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fantasy_player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offering_team_id')->nullable()->constrained('fantasy_teams')->nullOnDelete();
            $table->foreignId('receiving_team_id')->nullable()->constrained('fantasy_teams')->nullOnDelete();
            $table->bigInteger('amount');
            $table->string('status')->default('PENDING')->comment('PENDING, ACCEPTED, REJECTED, EXPIRED, WITHDRAWN');
            $table->string('type')->comment('MARKET_BID, DIRECT_OFFER, CLAUSE_PAYMENT');
            $table->timestamp('expires_at')->nullable();
            $table->string('external_id')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['fantasy_league_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fantasy_offers');
    }
};
