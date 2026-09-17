<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fantasy_clause_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fantasy_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fantasy_league_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fantasy_player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_team_id')->constrained('fantasy_teams')->cascadeOnDelete();
            $table->string('player_team_id')->comment('snapshotted at order time, see fantasy_team_players.player_team_id');
            $table->bigInteger('clause_value_at_order');
            $table->bigInteger('pending_confirmation_clause_value')->nullable()->comment('set when a rise is detected and awaiting user confirmation');
            $table->bigInteger('executed_clause_value')->nullable()->comment('what was actually paid, once EXECUTED');
            $table->string('status')->default('PENDING');
            $table->text('error_message')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['fantasy_account_id', 'fantasy_player_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fantasy_clause_purchase_orders');
    }
};
