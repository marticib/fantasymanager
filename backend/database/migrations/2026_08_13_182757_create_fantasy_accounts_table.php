<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fantasy_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('fantasy_user_id')->nullable()->comment('user id returned by /v4/user/me');
            $table->string('nickname')->nullable();
            $table->text('access_token')->nullable()->comment('encrypted');
            $table->text('refresh_token')->nullable()->comment('encrypted');
            $table->timestamp('token_expires_at')->nullable();
            $table->foreignId('active_league_id')->nullable();
            $table->foreignId('active_team_id')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fantasy_accounts');
    }
};
