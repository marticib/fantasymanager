<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fantasy_decision_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fantasy_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fantasy_player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fantasy_league_id')->nullable()->constrained()->nullOnDelete();

            $table->string('decision_type')->comment('ROSTER (own player), MARKET_BUY (market listing), RIVAL_CLAUSE (rival clause)');
            $table->string('action')->comment('HOLD, SELL, LOCK_CLAUSE, BUY, DO_NOT_CHASE, PAY_CLAUSE, CONSIDER, WAIT, DO_NOT_BUY, DO_NOT_PAY');

            $table->bigInteger('current_market_value')->nullable();
            $table->bigInteger('reference_value')->nullable()->comment('acquisitionPrice / clauseValue / sell reference used by this decision');
            $table->bigInteger('projected_value')->nullable()->comment('projection at the evaluation horizon, for predictionError');

            $table->integer('main_score')->nullable()->comment('the score the action was primarily decided on');
            $table->integer('confidence')->nullable()->comment('dataQuality-based confidence at snapshot time');

            $table->unsignedSmallInteger('horizon_days')->comment('how many days out this decision is graded against');
            $table->date('snapshot_date')->comment('calendar day the snapshot was taken — one row per account/player/action/day');

            $table->json('payload')->comment('full decision context at the time (scores, projections, dataQuality, reason, raw engine output) — immutable');
            $table->string('algorithm_version')->comment('config(fantasy.backtest.algorithm_version) at snapshot time');

            $table->string('status')->default('PENDING')->comment('PENDING, EVALUATED, INSUFFICIENT_DATA, NOT_EVALUABLE');
            $table->json('outcome')->nullable()->comment('realProfit, realROI, predictionError, favorable — filled by fantasy:evaluate-decisions');

            $table->timestamp('generated_at')->comment('when the decision was actually made, not when this row was written');
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();

            $table->unique(['fantasy_account_id', 'fantasy_player_id', 'action', 'snapshot_date']);
            $table->index(['status', 'horizon_days']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fantasy_decision_snapshots');
    }
};
