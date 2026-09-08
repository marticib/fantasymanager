<?php

namespace App\Providers;

use App\Contracts\RecommendationReasoningInterface;
use App\Services\Recommendation\DeterministicReasoningService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Swap this binding for an LLM-backed implementation later; the
        // engine itself never changes (see RecommendationReasoningInterface).
        $this->app->bind(RecommendationReasoningInterface::class, DeterministicReasoningService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
