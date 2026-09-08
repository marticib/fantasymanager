<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fantasy_accounts', function (Blueprint $table) {
            $table->string('token_client_id')->nullable()->after('refresh_token')
                ->comment('B2C client_id that issued the current tokens, so refresh() uses the matching one. Null = tokens pasted from an unknown session, refresh falls back to the default web client.');
        });
    }

    public function down(): void
    {
        Schema::table('fantasy_accounts', function (Blueprint $table) {
            $table->dropColumn('token_client_id');
        });
    }
};
