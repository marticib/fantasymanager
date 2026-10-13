<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fantasy_teams', function (Blueprint $table) {
            $table->boolean('auto_list_squad')->default(false)->after('is_mine')
                ->comment('When true, every sync lists any roster player not already on the market, at their current market value — see Automation/SquadListingService.');
        });
    }

    public function down(): void
    {
        Schema::table('fantasy_teams', function (Blueprint $table) {
            $table->dropColumn('auto_list_squad');
        });
    }
};
