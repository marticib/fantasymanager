<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fantasy_alerts', function (Blueprint $table) {
            $table->string('link')->nullable()->after('message')->comment('In-app path the notification opens, e.g. /sales');
            $table->string('dedupe_key')->nullable()->after('link')->comment('Same event seen again by a later sync never creates a second alert');
            $table->unique(['fantasy_account_id', 'dedupe_key']);
        });

        Schema::table('fantasy_accounts', function (Blueprint $table) {
            $table->timestamp('laliga_session_expired_at')->nullable()->after('token_expires_at')
                ->comment('Set when LaLiga rejects our tokens for good; cleared on any successful refresh or new tokens');
        });
    }

    public function down(): void
    {
        Schema::table('fantasy_alerts', function (Blueprint $table) {
            $table->dropUnique(['fantasy_account_id', 'dedupe_key']);
            $table->dropColumn(['link', 'dedupe_key']);
        });

        Schema::table('fantasy_accounts', function (Blueprint $table) {
            $table->dropColumn('laliga_session_expired_at');
        });
    }
};
