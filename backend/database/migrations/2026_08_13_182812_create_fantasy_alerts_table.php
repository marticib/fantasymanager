<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fantasy_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fantasy_account_id')->constrained()->cascadeOnDelete();
            $table->string('type')->comment('BUY_OPPORTUNITY, PRICE_DROP, CLAUSE_OPPORTUNITY, OFFER_RECEIVED, MARKET_AVAILABILITY, RISK_OF_LOSS');
            $table->string('title');
            $table->text('message');
            $table->foreignId('fantasy_player_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fantasy_recommendation_id')->nullable()->constrained('fantasy_recommendations')->nullOnDelete();
            $table->string('severity')->default('INFO')->comment('INFO, WARNING, CRITICAL');
            $table->string('channel')->default('IN_APP')->comment('IN_APP, EMAIL, TELEGRAM, PUSH — only IN_APP delivered for now');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['fantasy_account_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fantasy_alerts');
    }
};
