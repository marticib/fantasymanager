<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fantasy_team_players', function (Blueprint $table) {
            $table->string('player_team_id')->nullable()->after('fantasy_player_id')
                ->comment('LaLiga roster-slot id ("this player on this team"), distinct from fantasy_player_id — required by checkShield()/payClause().');
        });
    }

    public function down(): void
    {
        Schema::table('fantasy_team_players', function (Blueprint $table) {
            $table->dropColumn('player_team_id');
        });
    }
};
