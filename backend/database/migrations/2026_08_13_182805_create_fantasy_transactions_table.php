<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fantasy_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fantasy_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fantasy_league_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fantasy_player_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->comment('BUY, SELL, CLAUSE_PAYMENT, OFFER_ACCEPTED, ...');
            $table->bigInteger('amount')->nullable();
            $table->foreignId('from_team_id')->nullable()->constrained('fantasy_teams')->nullOnDelete();
            $table->foreignId('to_team_id')->nullable()->constrained('fantasy_teams')->nullOnDelete();
            $table->timestamp('occurred_at')->nullable();
            $table->string('external_id')->nullable()->comment('id from the LaLiga activity feed, for dedupe');
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['fantasy_league_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fantasy_transactions');
    }
};
