<?php

namespace App\Console\Commands\Concerns;

use App\Models\FantasyAccount;
use Illuminate\Support\Collection;

trait ResolvesFantasyAccounts
{
    /**
     * @return Collection<int, FantasyAccount>
     */
    protected function resolveAccounts(): Collection
    {
        $query = FantasyAccount::query()->whereNotNull('access_token');

        if ($accountId = $this->option('account')) {
            $query->where('id', $accountId);
        }

        return $query->get();
    }
}
