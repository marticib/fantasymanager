<?php

namespace App\Services\Notifications;

use App\Models\FantasyAccount;
use App\Models\FantasyAlert;
use App\Models\FantasyMarketPlayer;
use App\Models\FantasyStanding;
use App\Models\FantasyTeamPlayer;
use App\Models\FantasyTransaction;
use App\Services\Recommendation\TeamValueDeltaService;
use App\Services\Recommendation\TodayActionsService;
use App\Services\Recommendation\TradeLedgerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Time-based notifications, checked every few minutes by
 * `fantasy:send-reminders` (each one deduplicated, so it fires once):
 * - your players whose clause is cheap to pay (once a day),
 * - the market closing within the hour (once per close),
 * - the Sunday-evening recap of the week (once per week).
 */
class ReminderService
{
    /** A clause less than this above the player's value is a bargain for a rival. */
    public const EXPOSED_PREMIUM = 0.10;

    /** Below this value a "stolen" player barely matters — not worth a notification. */
    private const EXPOSED_MIN_VALUE = 2_000_000;

    private const MARKET_REMINDER_MINUTES = 60;

    private const LOCAL_TZ = 'Europe/Madrid';

    public function __construct(
        private readonly AlertService $alerts,
        private readonly TodayActionsService $todayActions,
        private readonly TeamValueDeltaService $teamValueDelta,
        private readonly TradeLedgerService $ledger,
    ) {}

    /** @return list<string> which reminders were raised */
    public function run(FantasyAccount $account): array
    {
        $raised = [];
        foreach (['clauseExposure', 'marketClosing', 'weeklySummary'] as $rule) {
            try {
                if ($this->{$rule}($account)) {
                    $raised[] = $rule;
                }
            } catch (Throwable $e) {
                Log::warning('fantasy.reminders.failed', ['rule' => $rule, 'account' => $account->id, 'message' => $e->getMessage()]);
            }
        }

        return $raised;
    }

    /** Your unprotected players a rival could take for little more than they're worth. */
    public function clauseExposure(FantasyAccount $account): bool
    {
        $teamId = $account->active_team_id;

        if (! $teamId) {
            return false;
        }

        $exposed = FantasyTeamPlayer::where('fantasy_team_id', $teamId)->with('player')->get()
            ->filter(function (FantasyTeamPlayer $e) {
                $value = (int) ($e->player?->market_value ?? 0);

                return $value >= self::EXPOSED_MIN_VALUE
                    && $e->clause_value !== null
                    && ! $e->is_locked
                    && (! $e->clause_locked_until || $e->clause_locked_until->isPast())
                    && $e->clause_value < $value * (1 + self::EXPOSED_PREMIUM);
            })
            ->sortByDesc(fn ($e) => $e->player->market_value)
            ->values();

        if ($exposed->isEmpty()) {
            return false;
        }

        $transfers = FantasyTransaction::where('fantasy_league_id', $account->active_league_id)
            ->where('type', FantasyTransaction::TYPE_TRANSFER)
            ->where('occurred_at', '>=', now()->subDays(7))
            ->where('to_team_id', '!=', $teamId)
            ->with('toTeam:id,name')
            ->get();
        $topBuyer = $transfers->groupBy('to_team_id')->sortByDesc(fn ($g) => $g->count())->first();

        $names = $exposed->take(4)->map(fn ($e) => sprintf('%s (+%s%%)', $e->player->name, number_format(($e->clause_value / $e->player->market_value - 1) * 100, 1, ',', '.')))->implode(', ');
        $activity = $transfers->isEmpty()
            ? 'Aquesta setmana cap rival ha pres jugadors a un altre.'
            : sprintf('Aquesta setmana els rivals han fet %d traspassos entre equips%s.', $transfers->count(), $topBuyer ? sprintf(' (%s: %d)', $topBuyer->first()->toTeam?->name, $topBuyer->count()) : '');

        return (bool) $this->alerts->notify(
            $account,
            FantasyAlert::TYPE_CLAUSE_EXPOSED,
            $exposed->count() === 1 ? $exposed[0]->player->name.' es pot prendre barat' : $exposed->count().' jugadors teus es poden prendre barat',
            "Clàusula a menys d'un 10% del valor: {$names}. {$activity} ".($exposed->count() === 1 ? 'Puja-li la clàusula si no el vols perdre.' : 'Puja\'ls la clàusula si no els vols perdre.'),
            [
                'link' => $exposed->count() === 1 ? '/players/'.$exposed[0]->fantasy_player_id : '/team',
                'severity' => FantasyAlert::SEVERITY_WARNING,
                'dedupe' => 'exposed-'.now(self::LOCAL_TZ)->toDateString(),
            ],
        );
    }

    /** One hour before the market closes: your bids, buys you haven't bid on, lineup problems. */
    public function marketClosing(FantasyAccount $account): bool
    {
        $league = $account->activeLeague;
        $teamId = $account->active_team_id;

        if (! $league || ! $teamId) {
            return false;
        }

        $closesAt = FantasyMarketPlayer::where('fantasy_league_id', $league->id)->where('is_on_market', true)
            ->where('expires_at', '>', now())->min('expires_at');

        if (! $closesAt) {
            return false;
        }

        $closesAt = Carbon::parse($closesAt);
        if (now()->diffInMinutes($closesAt, false) > self::MARKET_REMINDER_MINUTES) {
            return false;
        }

        $listings = FantasyMarketPlayer::where('fantasy_league_id', $league->id)->where('is_on_market', true)->with('player:id,name')->get();
        $bids = $listings->filter(fn ($m) => data_get($m->raw_payload, 'bid.id'));
        $bidIds = $bids->pluck('id')->all();

        $lines = [];
        if ($bids->isNotEmpty()) {
            $lines[] = sprintf('Tens %d %s: %s.', $bids->count(), $bids->count() === 1 ? 'oferta activa' : 'ofertes actives', $bids->map(fn ($m) => $m->player?->name)->filter()->implode(', '));
        }

        $today = $this->todayActions->build($account);
        $buys = collect([...($today['actions']['urgent'] ?? []), ...($today['actions']['opportunity'] ?? [])])
            ->filter(fn ($a) => ($a['type'] ?? null) === 'BUY' && ! in_array($a['marketPlayerId'] ?? null, $bidIds, true))
            ->take(3);
        if ($buys->isNotEmpty()) {
            $lines[] = 'Compres recomanades sense oferta: '.$buys->map(fn ($a) => $a['player']['name'] ?? '?')->implode(', ').'.';
        }

        $unavailable = FantasyTeamPlayer::where('fantasy_team_id', $teamId)->where('is_starter', true)->with('player:id,name,status')->get()
            ->filter(fn ($e) => in_array(strtolower((string) $e->player?->status), ['injured', 'doubtful', 'sanctioned'], true));
        if ($unavailable->isNotEmpty()) {
            $label = ['injured' => 'lesionat', 'doubtful' => 'dubte', 'sanctioned' => 'sancionat'];
            $lines[] = "A l'onze: ".$unavailable->map(fn ($e) => $e->player->name.' ('.$label[strtolower($e->player->status)].')')->implode(', ').'.';
        }

        if ($lines === []) {
            return false;
        }

        return (bool) $this->alerts->notify(
            $account,
            FantasyAlert::TYPE_MARKET_CLOSING,
            'El mercat tanca a les '.$closesAt->copy()->setTimezone(self::LOCAL_TZ)->format('H:i'),
            implode(' ', $lines),
            ['link' => '/market', 'dedupe' => 'market-close-'.$closesAt->format('YmdHi')],
        );
    }

    /** Sunday from 20:00 (Spanish time): how the week went. */
    public function weeklySummary(FantasyAccount $account): bool
    {
        $local = now(self::LOCAL_TZ);
        $teamId = $account->active_team_id;

        if (! $local->isSunday() || $local->hour < 20 || ! $teamId) {
            return false;
        }

        $since = now()->subDays(7);
        $lines = [];

        $delta = $this->teamValueDelta->deltaSince($teamId, $since);
        if ($delta !== null) {
            $lines[] = 'Valor de l\'equip: '.$this->signedMoney($delta).'.';
        }

        $now = FantasyStanding::where('fantasy_team_id', $teamId)->orderByDesc('captured_at')->first();
        $before = FantasyStanding::where('fantasy_team_id', $teamId)->where('captured_at', '<=', $since)->orderByDesc('captured_at')->first();
        if ($now) {
            $move = $before ? $before->position - $now->position : 0;
            $lines[] = sprintf('Classificació: %dè%s.', $now->position, $move > 0 ? " (has pujat {$move})" : ($move < 0 ? ' (has baixat '.abs($move).')' : ''));
        }

        $ledger = $this->ledger->build($account, $since);
        if ($ledger) {
            $s = $ledger['summary'];
            if ($s['rewards'] > 0) {
                $lines[] = 'Premis de jornada: '.$this->money($s['rewards']).'.';
            }
            if ($s['operations'] > 0) {
                $line = sprintf('%d %s', $s['operations'], $s['operations'] === 1 ? 'venda' : 'vendes');
                if ($s['realizedProfit'] !== null) {
                    $line .= ' amb un resultat de '.$this->signedMoney($s['realizedProfit']);
                }
                if ($s['best'] && $s['best']['profit'] > 0) {
                    $line .= sprintf('; la millor, %s (%s)', $s['best']['player']['name'] ?? '?', $this->signedMoney($s['best']['profit']));
                }
                $lines[] = $line.'.';
            }
        }

        if ($lines === []) {
            return false;
        }

        return (bool) $this->alerts->notify(
            $account,
            FantasyAlert::TYPE_WEEKLY_SUMMARY,
            'Resum de la setmana',
            implode(' ', $lines),
            ['link' => '/history', 'dedupe' => 'weekly-'.$local->format('o-W')],
        );
    }

    private function money(int $value): string
    {
        return number_format($value, 0, ',', '.').' €';
    }

    private function signedMoney(int $value): string
    {
        return ($value > 0 ? '+' : ($value < 0 ? '−' : '')).$this->money(abs($value));
    }
}
