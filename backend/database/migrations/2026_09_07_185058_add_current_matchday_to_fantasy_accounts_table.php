<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fantasy_accounts', function (Blueprint $table) {
            $table->unsignedSmallInteger('current_matchday')->nullable()->after('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('fantasy_accounts', function (Blueprint $table) {
            $table->dropColumn('current_matchday');
        });
    }
};
