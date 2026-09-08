<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fantasy_clauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fantasy_team_player_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('clause_value');
            $table->decimal('opportunity_score', 5, 2)->nullable()->comment('Clause Opportunity Score, 0-100');
            $table->boolean('is_recommended')->default(false);
            $table->json('analysis')->nullable()->comment('breakdown behind the opportunity score');
            $table->timestamp('captured_at');

            $table->index(['fantasy_team_player_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fantasy_clauses');
    }
};
