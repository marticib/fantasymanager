<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fantasy_teams', function (Blueprint $table) {
            $table->string('manager_external_id')->nullable()->after('external_id')
                ->comment('LaLiga manager id — how the league activity feed refers to a team');
            $table->index(['fantasy_league_id', 'manager_external_id']);
        });

        Schema::table('fantasy_transactions', function (Blueprint $table) {
            $table->index(['fantasy_league_id', 'occurred_at']);
        });

        Schema::table('fantasy_accounts', function (Blueprint $table) {
            $table->string('telegram_chat_id')->nullable()->comment('Telegram chat that receives this account\'s notifications');
            $table->string('telegram_link_code')->nullable()->comment('One-time code the user sends to the bot to link the chat');
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fantasy_account_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint_hash', 64)->unique()->comment('sha256 of the endpoint, which is too long to index directly');
            $table->text('endpoint');
            $table->string('public_key');
            $table->string('auth_token');
            $table->string('content_encoding')->default('aes128gcm');
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');

        Schema::table('fantasy_accounts', function (Blueprint $table) {
            $table->dropColumn(['telegram_chat_id', 'telegram_link_code']);
        });

        Schema::table('fantasy_transactions', function (Blueprint $table) {
            $table->dropIndex(['fantasy_league_id', 'occurred_at']);
        });

        Schema::table('fantasy_teams', function (Blueprint $table) {
            $table->dropIndex(['fantasy_league_id', 'manager_external_id']);
            $table->dropColumn('manager_external_id');
        });
    }
};
