<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fantasy_leagues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fantasy_account_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->comment('LaLiga Fantasy league id');
            $table->string('name');
            $table->string('mode')->nullable()->comment('e.g. classic, draft, private');
            $table->unsignedInteger('team_count')->nullable();
            $table->json('raw_payload')->nullable()->comment('last raw API payload for fields we have not modeled yet');
            $table->timestamps();

            $table->unique(['fantasy_account_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fantasy_leagues');
    }
};
