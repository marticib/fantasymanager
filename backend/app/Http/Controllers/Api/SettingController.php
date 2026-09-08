<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Recommendation\FantasySettingsService;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index(Request $request, FantasySettingsService $settings)
    {
        $account = $this->currentAccount($request);

        return response()->json([
            'rules' => $settings->rules($account),
            'scoreWeights' => $settings->scoreWeights($account),
        ]);
    }

    public function update(Request $request, FantasySettingsService $settings)
    {
        $account = $this->currentAccount($request);

        $data = $request->validate([
            'rules' => ['sometimes', 'array'],
            'scoreWeights' => ['sometimes', 'array'],
        ]);

        foreach ($data['rules'] ?? [] as $key => $value) {
            if (array_key_exists($key, config('fantasy.rules_defaults'))) {
                $settings->set($key, $value, $account);
            }
        }

        foreach ($data['scoreWeights'] ?? [] as $key => $value) {
            if (array_key_exists($key, config('fantasy.score_weights_defaults'))) {
                $settings->set($key, $value, $account);
            }
        }

        return response()->json([
            'rules' => $settings->rules($account),
            'scoreWeights' => $settings->scoreWeights($account),
        ]);
    }
}
