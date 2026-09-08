<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fantasy_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fantasy_account_id')->nullable()->constrained()->cascadeOnDelete()
                ->comment('null = global default, overridden per-account when present');
            $table->string('key');
            $table->json('value');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['fantasy_account_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fantasy_settings');
    }
};
