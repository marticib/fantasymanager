<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FantasyRecommendationResource;
use App\Models\FantasyRecommendation;
use Illuminate\Http\Request;

class RecommendationController extends Controller
{
    public function index(Request $request)
    {
        $account = $this->currentAccount($request);

        $query = FantasyRecommendation::query()
            ->where('fantasy_account_id', $account->id)
            ->with('player');

        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($priority = $request->query('priority')) {
            $query->where('priority', $priority);
        }

        $recommendations = $query->orderByDesc('generated_at')->paginate(min((int) $request->query('per_page', 50), 200));

        return FantasyRecommendationResource::collection($recommendations);
    }

    public function today(Request $request)
    {
        $account = $this->currentAccount($request);

        $recommendations = FantasyRecommendation::query()
            ->where('fantasy_account_id', $account->id)
            ->where('status', FantasyRecommendation::STATUS_ACTIVE)
            ->with('player')
            ->get()
            ->sortBy(fn (FantasyRecommendation $r) => [-FantasyRecommendation::priorityWeight($r->priority), -$r->confidence])
            ->values();

        return FantasyRecommendationResource::collection($recommendations);
    }
}
