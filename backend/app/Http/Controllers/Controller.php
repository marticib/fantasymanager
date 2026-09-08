<?php

namespace App\Http\Controllers;

use App\Models\FantasyAccount;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * MVP scope: one LaLiga Fantasy account per user. The schema allows more
     * (fantasy_accounts.user_id is a plain FK, not unique) so multi-account
     * support later is a controller-layer change only.
     */
    protected function currentAccount(Request $request): FantasyAccount
    {
        return $request->user()->fantasyAccounts()->firstOrCreate([]);
    }
}
