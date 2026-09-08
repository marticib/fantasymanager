<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fantasy_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fantasy_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fantasy_league_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fantasy_player_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action')->comment('BUY, SELL, HOLD, MARKET_LIST, RAISE_BID, WITHDRAW_BID, PAY_CLAUSE, DO_NOT_PAY_CLAUSE, LOCK_CLAUSE, UNLOCK_CLAUSE');
            $table->string('priority')->comment('CRITICAL, HIGH, MEDIUM, LOW');
            $table->unsignedTinyInteger('confidence')->comment('0-100');
            $table->text('reason');
            $table->json('explanation')->nullable()->comment('pros/cons bullet breakdown for explainability');
            $table->bigInteger('financial_impact')->nullable();
            $table->bigInteger('recommended_amount')->nullable();
            $table->bigInteger('max_amount')->nullable();
            $table->decimal('fantasy_score', 5, 2)->nullable();
            $table->string('status')->default('ACTIVE')->comment('ACTIVE, EXPIRED, DISMISSED, ACTED_ON');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('generated_at');
            $table->json('outcome')->nullable()->comment('filled later by history/backtesting evaluation');
            $table->timestamps();

            $table->index(['fantasy_account_id', 'status', 'priority']);
            $table->index(['fantasy_player_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fantasy_recommendations');
    }
};
