<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClauseController;
use App\Http\Controllers\Api\ClausePurchaseOrderController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FantasyAccountController;
use App\Http\Controllers\Api\HistoryController;
use App\Http\Controllers\Api\LeagueController;
use App\Http\Controllers\Api\MarketController;
use App\Http\Controllers\Api\PlayerController;
use App\Http\Controllers\Api\RecommendationController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\StandingController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\TradingController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // LaLiga Fantasy account / token configuration
    Route::get('/fantasy-account', [FantasyAccountController::class, 'show']);
    Route::post('/fantasy-account/tokens', [FantasyAccountController::class, 'storeTokens']);
    Route::post('/fantasy-account/oauth/start', [FantasyAccountController::class, 'startOAuth']);
    Route::post('/fantasy-account/oauth/finish', [FantasyAccountController::class, 'finishOAuth']);
    Route::delete('/fantasy-account', [FantasyAccountController::class, 'destroy']);

    // Leagues
    Route::get('/leagues', [LeagueController::class, 'index']);
    Route::post('/leagues/{league}/select', [LeagueController::class, 'select']);

    // Dashboard — "What should I do today?"
    Route::get('/dashboard/today', [DashboardController::class, 'today']);

    // Team
    Route::get('/team', [TeamController::class, 'show']);
    Route::get('/team/analysis', [TeamController::class, 'analysis']);
    Route::get('/team/lineup', [TeamController::class, 'lineup']);

    // Market
    Route::get('/market', [MarketController::class, 'index']);
    Route::get('/market/opportunities', [MarketController::class, 'opportunities']);
    Route::get('/market/{marketPlayer}/buy-analysis', [MarketController::class, 'buyAnalysis']);

    // Players
    Route::get('/players', [PlayerController::class, 'index']);
    Route::get('/players/{player}', [PlayerController::class, 'show']);

    // Recommendations
    Route::get('/recommendations', [RecommendationController::class, 'index']);
    Route::get('/recommendations/today', [RecommendationController::class, 'today']);

    // Trading & clauses (scaffolded now, fleshed out post-MVP)
    Route::get('/trading/opportunities', [TradingController::class, 'opportunities']);
    Route::get('/clauses/opportunities', [ClauseController::class, 'opportunities']);

    Route::get('/clause-orders', [ClausePurchaseOrderController::class, 'index']);
    Route::post('/clause-orders', [ClausePurchaseOrderController::class, 'store']);
    Route::post('/clause-orders/{order}/confirm', [ClausePurchaseOrderController::class, 'confirm']);
    Route::delete('/clause-orders/{order}', [ClausePurchaseOrderController::class, 'destroy']);

    // Standings
    Route::get('/standings', [StandingController::class, 'index']);
    Route::get('/standings/{team}', [TeamController::class, 'rival']);

    // History
    Route::get('/history/team-value', [HistoryController::class, 'teamValue']);
    Route::get('/history/player/{player}', [HistoryController::class, 'player']);
    Route::get('/history/assistant-performance', [HistoryController::class, 'assistantPerformance']);
    Route::get('/history/decisions', [HistoryController::class, 'decisions']);
    Route::get('/history/decisions/{decision}', [HistoryController::class, 'decision']);

    // Settings (configurable rules / weights)
    Route::get('/settings', [SettingController::class, 'index']);
    Route::put('/settings', [SettingController::class, 'update']);

    // Manual sync trigger (read-only sync, no trading actions)
    Route::post('/fantasy/sync', [SyncController::class, 'sync']);
});
