<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FantasyAccount;
use App\Services\Recommendation\FantasySettingsService;
use App\Services\Trading\BuildXiOptimizer;
use App\Services\Trading\BuildXiService;
use App\Services\Trading\MakeMoneyService;
use App\Services\Trading\TradingFreshnessService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The Trading screen's two tabs. Thin on purpose: every calculation lives in
 * App\Services\Trading (which in turn reuses the market/clause economic
 * engines) — this only validates the query, caches, and attaches the
 * data-freshness picture the UI must show next to any recommendation.
 */
class TradingController extends Controller
{
    public function __construct(
        private readonly TradingFreshnessService $freshness,
        private readonly FantasySettingsService $settings,
    ) {}

    public function buildXi(Request $request, BuildXiService $service): JsonResponse
    {
        $account = $this->currentAccount($request);
        $strategy = $request->query('strategy', 'balanced');

        if (! in_array($strategy, BuildXiOptimizer::STRATEGIES, true)) {
            return response()->json(['message' => 'Estratègia desconeguda.', 'allowed' => BuildXiOptimizer::STRATEGIES], 422);
        }
        if (($horizon = $this->horizon($request)) === null) {
            return $this->invalidHorizon();
        }

        return $this->respond($account, 'build-xi', ['strategy' => $strategy, 'horizon' => $horizon], fn () => $service->build($account, $strategy, $horizon));
    }

    public function makeMoney(Request $request, MakeMoneyService $service): JsonResponse
    {
        $account = $this->currentAccount($request);

        if (($horizon = $this->horizon($request)) === null) {
            return $this->invalidHorizon();
        }
        $respectReserve = $request->boolean('respect_reserve', true);

        return $this->respond($account, 'make-money', ['horizon' => $horizon, 'reserve' => $respectReserve], fn () => $service->build($account, $horizon, $respectReserve));
    }

    private function horizon(Request $request): ?int
    {
        $horizon = (int) $request->query('horizon', config('fantasy.trading.default_horizon_days'));

        return in_array($horizon, config('fantasy.trading.horizons'), true) ? $horizon : null;
    }

    private function invalidHorizon(): JsonResponse
    {
        return response()->json(['message' => 'Horitzó no vàlid.', 'allowed' => config('fantasy.trading.horizons')], 422);
    }

    /**
     * The computed plan is cached briefly, keyed on everything that can
     * change it (each source's last-sync stamp, cash, the account's rules) so
     * a fresh sync or a settings edit is never masked; the freshness block is
     * always recomputed so its ages stay true.
     *
     * @param  array<string, mixed>  $params
     */
    private function respond(FantasyAccount $account, string $name, array $params, Closure $compute): JsonResponse
    {
        $freshness = $this->freshness->assess($account);
        $ttl = (int) config('fantasy.trading.cache_seconds');

        $key = 'trading:'.$name.':'.md5(json_encode([
            $account->id,
            $account->active_league_id,
            $account->active_team_id,
            $account->activeTeam?->money,
            array_column($freshness['sources'], 'lastUpdatedAt'),
            $this->settings->rules($account),
            $params,
        ]));

        $data = $ttl > 0 ? Cache::remember($key, $ttl, $compute) : $compute();

        return response()->json([
            'data' => $data,
            'meta' => [
                'freshness' => $freshness,
                'generatedAt' => now()->toIso8601String(),
                'horizons' => config('fantasy.trading.horizons'),
                'defaultHorizon' => (int) config('fantasy.trading.default_horizon_days'),
            ],
        ]);
    }
}
