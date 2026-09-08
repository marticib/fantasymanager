<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fantasy_daily_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fantasy_account_id')->constrained()->cascadeOnDelete();
            $table->date('report_date');
            $table->json('summary')->comment('teamValue, cash, availableCapital, action counts, etc.');
            $table->json('top_actions')->nullable()->comment('denormalized snapshot of top priority actions of the day');
            $table->timestamps();

            $table->unique(['fantasy_account_id', 'report_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fantasy_daily_reports');
    }
};
