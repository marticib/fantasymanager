<?php

namespace App\Services\Automation;

use App\Models\FantasyAccount;
use App\Models\FantasyMarketPlayer;
use App\Services\FantasyApi\Exceptions\FantasyApiException;
use App\Services\FantasyApi\FantasyMarketService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Backs `FantasyTeam.auto_list_squad`: while it's on, every roster player not
 * already listed gets put up for sale at their current market value, via the
 * real `FantasyMarketService::sellToMarket()` write call (see that method's
 * docblock for how its shape was confirmed — cross-checked against a
 * community client hitting the same API, same as accept/rejectOffer()).
 * Called from `FantasySyncService::syncTeam()` right after the roster itself
 * syncs, so a newly-acquired player gets listed on the very next sync rather
 * than needing a separate scheduled command.
 */
class SquadListingService
{
    public function __construct(private readonly FantasyMarketService $marketService) {}

    /**
     * @return array{listed: int, failed: int}
     */
    public function listMissingPlayers(FantasyAccount $account): array
    {
        $team = $account->activeTeam;
        $league = $account->activeLeague;

        if (! $team || ! $league || ! $team->auto_list_squad) {
            return ['listed' => 0, 'failed' => 0];
        }

        $alreadyListed = FantasyMarketPlayer::where('fantasy_league_id', $league->id)
            ->where('seller_team_id', $team->id)
            ->where('is_on_market', true)
            ->pluck('fantasy_player_id')
            ->all();

        $toList = $team->teamPlayers()->with('player')
            ->whereNotIn('fantasy_player_id', $alreadyListed ?: [0])
            ->get()
            ->filter(fn ($teamPlayer) => $teamPlayer->player && $teamPlayer->player->market_value > 0);

        $listed = 0;
        $failed = 0;

        foreach ($toList as $teamPlayer) {
            $player = $teamPlayer->player;

            // The market/sell endpoint's `playerId` body field is misleadingly
            // named: it actually expects the *roster-slot* id (`player_team_id`,
            // what FantasyRosterEntryDTO calls `playerTeamId`), not the
            // player's own global id — confirmed against the same community
            // client's actual UI call site (not just its wrapper signature,
            // which uses the same misleading parameter name we originally
            // copied). Sending the global id instead gets a 400 for *every*
            // player with a misleading "not in your team anymore" message,
            // which this learned the hard way — never auto-delete the roster
            // row on that error, it does not mean what it sounds like here.
            if (! $teamPlayer->player_team_id) {
                $failed++;
                Log::channel('fantasy_api')->warning('fantasy.auto_list_squad.missing_player_team_id', [
                    'team_id' => $team->id,
                    'player_id' => $player->id,
                ]);

                continue;
            }

            try {
                $this->marketService->sellToMarket($account, $league->external_id, $teamPlayer->player_team_id, (int) $player->market_value);

                // Optimistic local record so a sync a few seconds later doesn't
                // try to re-list the same player before the much slower
                // fantasy:sync-market job has had a chance to pick it up and
                // overwrite this with the real listing id/asking price/expiry.
                FantasyMarketPlayer::updateOrCreate(
                    ['fantasy_league_id' => $league->id, 'fantasy_player_id' => $player->id],
                    [
                        'seller_team_id' => $team->id,
                        'market_value' => $player->market_value,
                        'asking_price' => $player->market_value,
                        'is_on_market' => true,
                    ],
                );

                $listed++;
            } catch (Throwable $e) {
                $failed++;
                Log::channel('fantasy_api')->warning('fantasy.auto_list_squad.failed', [
                    'team_id' => $team->id,
                    'player_id' => $player->id,
                    'message' => $this->describeFailure($e),
                ]);
            }
        }

        return ['listed' => $listed, 'failed' => $failed];
    }

    // LaLiga's real sellToMarket response shape is unconfirmed by our own
    // traffic (never called before this feature) — this surfaces whatever
    // the API actually said instead of a bare "rejected (400)", same
    // approach as ClausePurchaseOrderService::describeFailure().
    private function describeFailure(Throwable $e): string
    {
        if (! $e instanceof FantasyApiException || $e->responseBody === null) {
            return $e->getMessage();
        }

        $body = $e->responseBody;
        $reason = is_array($body)
            ? ($body['message'] ?? $body['error'] ?? $body['errorMessage'] ?? $body['detail'] ?? null)
            : null;

        return $reason ?? $e->getMessage().' — '.json_encode($body);
    }
}
