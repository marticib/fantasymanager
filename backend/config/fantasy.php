<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LaLiga Fantasy API — unofficial, reverse-engineered, unstable
    |--------------------------------------------------------------------------
    |
    | There is no official public API or documentation. Endpoints, payload
    | shapes and the auth flow below were cross-checked against the public
    | community project github.com/Externoak/LaLigaApp (MIT-style, temporada
    | 26/27) in addition to the endpoints supplied by the product owner.
    | Treat every field in FantasyApi/DTOs as "best effort" — anything not
    | explicitly modeled is preserved in a raw_payload JSON column so no data
    | is lost if the schema drifts.
    |
    | If LaLiga changes a path or a payload shape, only FantasyApiClient (and
    | the relevant *Service/DTO) should need to change — never the rest of
    | the app.
    |
    */

    'api' => [
        'base_url' => env('FANTASY_API_BASE_URL', 'https://fantasy-api.llt-services.com/api'),
        'competition_id' => env('FANTASY_API_COMPETITION_ID', 1),
        'timeout' => (int) env('FANTASY_API_TIMEOUT', 15),
        'retry_times' => (int) env('FANTASY_API_RETRY_TIMES', 3),
        'retry_sleep_ms' => (int) env('FANTASY_API_RETRY_SLEEP_MS', 500),
        // Default query param every request seems to accept/expect.
        'default_query' => ['x-lang' => 'es'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Auth — OAuth2 / Azure AD B2C
    |--------------------------------------------------------------------------
    |
    | LaLiga Fantasy authenticates through an Azure AD B2C tenant
    | (laligadspprob2c.onmicrosoft.com). We deliberately do NOT implement the
    | resource-owner-password grant here (it would require handling the
    | user's LaLiga *password*, which the product spec explicitly forbids
    | storing).
    |
    | The primary flow is Authorization Code + PKCE, the same one LaLiga's
    | own apps use for Google/Apple sign-in — cross-checked against two
    | independent community projects (Externoak/LaLigaApp and
    | jonortega20/fantasybot). We confirmed empirically (AADB2C90006
    | redirect_uri mismatch) that Azure B2C only accepts the *registered*
    | redirect_uri below for this client_id — never a domain we control — so
    | the interactive login can't complete as a normal browser redirect back
    | into this app. Instead: we open LaLiga's real login page with our own
    | PKCE challenge: the browser's final hop to `oauth_redirect_uri` fails to
    | open (no app registered for that custom scheme), but the attempted URL
    | — with the authorization `code` in it — is visible in DevTools' Network
    | tab (a "(canceled)" request) or sometimes the address bar. The user
    | pastes that URL back into the app; FantasyAuthService exchanges the code
    | for tokens server-side. See FantasyAuthService::startInteractiveLogin()/
    | finishInteractiveLogin(). Manually pasting an access_token/refresh_token
    | from an already-authenticated session (storeManualTokens()) remains
    | supported as a fallback.
    |
    */
    'auth' => [
        'token_endpoint' => env('FANTASY_AUTH_TOKEN_ENDPOINT', 'https://login.laliga.es/laligadspprob2c.onmicrosoft.com/oauth2/v2.0/token'),
        'authorize_endpoint' => env('FANTASY_AUTH_AUTHORIZE_ENDPOINT', 'https://login.laliga.es/laligadspprob2c.onmicrosoft.com/oauth2/v2.0/authorize'),
        'refresh_policy' => env('FANTASY_AUTH_REFRESH_POLICY', 'B2C_1A_5ULAIP_PARAMETRIZED_SIGNIN'),
        'refresh_client_id' => env('FANTASY_AUTH_REFRESH_CLIENT_ID', '6457fa17-1224-416a-b21a-ee6ce76e9bc0'),
        'refresh_scope' => env('FANTASY_AUTH_REFRESH_SCOPE', 'openid offline_access'),
        // Refresh proactively once the token has less than this many seconds left.
        'refresh_margin_seconds' => (int) env('FANTASY_AUTH_REFRESH_MARGIN_SECONDS', 300),

        // Interactive Authorization Code + PKCE login (native/email client —
        // the only one whose registered redirect_uri isn't locked to LaLiga's
        // own domain).
        'oauth_client_id' => env('FANTASY_AUTH_OAUTH_CLIENT_ID', 'af88bcff-1157-40a0-b579-030728aacf0b'),
        'oauth_redirect_uri' => env('FANTASY_AUTH_OAUTH_REDIRECT_URI', 'authredirect://com.lfp.laligafantasy'),
        'oauth_scope' => env('FANTASY_AUTH_OAUTH_SCOPE', 'openid offline_access'),
        // How long a generated login link + PKCE verifier stays valid before the user must restart.
        'oauth_session_ttl_seconds' => (int) env('FANTASY_AUTH_OAUTH_SESSION_TTL', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync frequencies (minutes) — read by the scheduler, overridable at
    | runtime through the fantasy_settings table without a deploy.
    |--------------------------------------------------------------------------
    */
    'sync' => [
        'market_frequency_minutes' => (int) env('FANTASY_SYNC_MARKET_FREQUENCY', 15),
        'team_frequency_minutes' => (int) env('FANTASY_SYNC_TEAM_FREQUENCY', 30),
        'players_frequency_minutes' => (int) env('FANTASY_SYNC_PLAYERS_FREQUENCY', 60),
        'league_frequency_minutes' => (int) env('FANTASY_SYNC_LEAGUE_FREQUENCY', 60),
        'recommendations_frequency_minutes' => (int) env('FANTASY_RECOMMENDATIONS_FREQUENCY', 30),
        'clauses_frequency_minutes' => (int) env('FANTASY_SYNC_CLAUSES_FREQUENCY', 120),
        // Tighter than clauses_frequency_minutes above on purpose: this one
        // drives ClausePurchaseOrderService, where responsiveness is the
        // whole point (racing other managers the moment a clause unlocks).
        'clause_orders_frequency_minutes' => (int) env('FANTASY_SYNC_CLAUSE_ORDERS_FREQUENCY', 5),
        'decision_snapshots_frequency_minutes' => (int) env('FANTASY_SNAPSHOT_DECISIONS_FREQUENCY', 720),
        'decision_evaluation_frequency_minutes' => (int) env('FANTASY_EVALUATE_DECISIONS_FREQUENCY', 720),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backtesting — fantasy:snapshot-decisions / fantasy:evaluate-decisions
    |--------------------------------------------------------------------------
    |
    | Snapshotting freezes what PlayerDecisionEngine/MarketBuyAnalysisService/
    | ClauseEconomicAnalysisService actually said on a given day (never a
    | second copy of their math); evaluation later grades that frozen
    | decision against what really happened, using the value the algorithm
    | itself would have needed to reach to be "correct" — read from the
    | snapshot's own `payload`, never recomputed with today's config.
    |
    */
    'backtest' => [
        // Bump this by hand whenever a scoring formula, weight, decay band or
        // threshold changes, so an old snapshot is never silently re-graded
        // as if it had been produced by a different algorithm.
        'algorithm_version' => env('FANTASY_ALGORITHM_VERSION', '2026.09.1'),

        // How many days out each action type is graded against — matches the
        // headline horizon each engine already reports (ROI14d for buy/clause,
        // projectedValue7d for the trade/sell side).
        'horizon_days' => [
            'BUY' => 14,
            'CONSIDER' => 14,
            'DO_NOT_CHASE' => 14,
            'PAY_CLAUSE' => 14,
            'SELL' => 7,
            'HOLD' => 7,
        ],

        // A prediction for "14 days out" rarely has a snapshot at exactly
        // +14d — the nearest real snapshot within this many extra days is
        // accepted as the outcome; beyond it, the decision is
        // INSUFFICIENT_DATA rather than guessing.
        'evaluation_tolerance_days' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | External (unofficial, third-party) data sources
    |--------------------------------------------------------------------------
    |
    | LaLiga's own API never returns historical market value (confirmed live
    | against several endpoints — see the fantasy_external_trends migration).
    | futbolfantasy.com's public market page independently tracks and
    | publishes 1/2/3/7/14/30-day value trends per player. This is a plain
    | HTML scrape of a site we don't control and aren't affiliated with — no
    | login, no API contract, matched back to our players by name (see
    | PlayerNameMatcher). Purely informational; see FutbolFantasySyncService
    | for why it never feeds the recommendation engine. Set `enabled` to
    | false to turn this off entirely.
    |
    */
    'external' => [
        'enabled' => (bool) env('FANTASY_EXTERNAL_TRENDS_ENABLED', true),
        'futbolfantasy_market_url' => env('FANTASY_EXTERNAL_FF_URL', 'https://www.futbolfantasy.com/analytics/laliga-fantasy/mercado'),
        'user_agent' => env('FANTASY_EXTERNAL_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36'),
        'timeout' => (int) env('FANTASY_EXTERNAL_TIMEOUT', 20),
        // A public page on a site we don't control: sync a few times a day, not aggressively.
        'sync_frequency_minutes' => (int) env('FANTASY_EXTERNAL_SYNC_FREQUENCY', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recommendation engine defaults — seeded into fantasy_settings on
    | first run, editable from the Settings screen from then on.
    |--------------------------------------------------------------------------
    */
    'rules_defaults' => [
        'sell_daily_drop_threshold' => -80000,
        'sell_3d_drop_threshold' => -180000,
        'buy_growth_threshold' => 60000,
        'minimum_cash_reserve' => 3000000,
        'maximum_bid_over_market_percentage' => 8.0,
        'trading_minimum_expected_profit' => 250000,
        'trading_horizon_days' => 4,
    ],

    'score_weights_defaults' => [
        'performance' => 25,
        'value_efficiency' => 20,
        'market_trend' => 15,
        'starter_likelihood' => 15,
        'calendar' => 10,
        'risk' => 10,
        'squad_fit' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Clause Economic Score — App\Services\Recommendation\ClauseEconomicAnalysisService
    |--------------------------------------------------------------------------
    |
    | Read directly by the service (not seeded into fantasy_settings like
    | rules_defaults/score_weights_defaults above): this module answers one
    | narrow, purely mathematical question — "does the projected value growth
    | repay the clause's premium?" — so it doesn't need a per-account UI
    | override, just a place to tune the model without touching code.
    |
    */
    'clause_analysis' => [
        // How much each recent window contributes to the blended expected
        // daily growth rate. Must sum to 1.0 — validated in
        // ClauseEconomicAnalysisService, not here, so a bad .env value fails
        // loudly instead of silently skewing every clause score.
        'trend_weights' => [
            '1d' => (float) env('FANTASY_CLAUSE_WEIGHT_1D', 0.50),
            '3d' => (float) env('FANTASY_CLAUSE_WEIGHT_3D', 0.30),
            '7d' => (float) env('FANTASY_CLAUSE_WEIGHT_7D', 0.20),
        ],

        // A trend rarely holds its exact daily pace for two straight weeks,
        // so each projected day's growth is the blended rate above times the
        // factor for the band its day-number falls in (day 1 = tomorrow).
        // Bands must be ordered and contiguous starting at day 1; a day past
        // the last band's `to` keeps compounding at that band's factor
        // rather than reverting to full or zero growth.
        'decay_bands' => [
            ['from' => 1, 'to' => 3, 'factor' => 1.00],
            ['from' => 4, 'to' => 7, 'factor' => 0.80],
            ['from' => 8, 'to' => 14, 'factor' => 0.50],
        ],

        // Break-even simulation gives up and reports "no break-even" beyond this horizon.
        'max_break_even_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Player Decision Engine — App\Services\Recommendation\PlayerDecisionEngine
    |--------------------------------------------------------------------------
    |
    | Decides HOLD / SELL / LOCK_CLAUSE for each of your own roster players by
    | comparing three independent 0-100 scores rather than reacting to any
    | single signal. Each *_weights group must sum to 1.0 (validated in the
    | service, not here, for the same reason as clause_analysis above).
    |
    */
    'player_decision' => [
        // A candidate action must beat the runner-up by at least this many
        // points to actually be recommended; otherwise the engine plays it
        // safe and says HOLD (see spec: small gaps mean "not clear enough").
        'decision_margin' => 8,

        'hold_weights' => [
            'sporting' => 0.40,
            'market' => 0.30,
            'scarcity' => 0.20,
            'clause_protection' => 0.10,
        ],

        'sell_weights' => [
            'liquidity' => 0.25,
            'avoided_loss' => 0.35,
            'upgrade' => 0.20,
            'low_future_value' => 0.20,
        ],

        'clause_weights' => [
            'theft_risk' => 0.45,
            'market_trend' => 0.30,
            'efficiency' => 0.25,
        ],

        // How the last 4 played gameweeks (real per-week points from the
        // LaLiga payload) blend with the season average into one expected
        // points figure. Renormalized over however many recent weeks
        // actually exist yet (early season / just signed) — see
        // PlayerDecisionEngine::expectedWeeklyPoints().
        'recent_form_weights' => [
            'w1' => 0.40,
            'w2' => 0.25,
            'w3' => 0.15,
            'w4' => 0.10,
            'season_avg' => 0.10,
        ],

        // Trade Score: "even though he's good, is this a good moment to sell
        // and bank the profit?" — an auxiliary signal that only ever adds a
        // bonus to the sell verdict (see PlayerDecisionEngine::decide()),
        // never a fourth final action on its own.
        'trade_weights' => [
            'appreciation' => 0.25,
            'momentum_exhaustion' => 0.30,
            'sell_premium' => 0.15,
            'capital_efficiency' => 0.10,
            'future_upside' => 0.20,
        ],

        'clause_timing' => [
            // A clause we've decided is worth raising still shouldn't be
            // raised the moment that becomes true — only once we're close to
            // the current protection window actually expiring (spending the
            // money any earlier buys no extra protection). This is added on
            // top of the last 24h of protection, not instead of it, so a
            // scheduler that only runs every few hours doesn't miss the
            // window right at expiry — see PlayerDecisionEngine::clauseTiming().
            'safety_margin_hours' => 6,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Market Buy Economic Score — App\Services\Recommendation\MarketBuyAnalysisService
    |--------------------------------------------------------------------------
    |
    | Same narrow, purely mathematical question as clause_analysis above, just
    | asked of a market listing instead of a clause: "if I buy this player at
    | this price, will the projected value growth repay it?" Deliberately
    | reuses clause_analysis.trend_weights/decay_bands/max_break_even_days
    | (via PlayerValueTrendCalculator and MarketValueProjector) rather than a
    | second copy of the same numbers — only the pieces that are genuinely
    | new to this model live here.
    |
    */
    'market_buy_analysis' => [
        // Break-even factor bands are steeper than clause_analysis's — a
        // clause you already own is a "when", a market bid competes against
        // other buyers today, so a slow-to-pay-off deal is a materially
        // weaker bet here. Ordered ascending by maxDays; a break-even beyond
        // the last band's maxDays uses 'beyond_factor', null uses 0.0.
        'break_even_factor_bands' => [
            ['maxDays' => 3, 'factor' => 1.00],
            ['maxDays' => 7, 'factor' => 0.90],
            ['maxDays' => 10, 'factor' => 0.80],
            ['maxDays' => 14, 'factor' => 0.65],
        ],
        'break_even_factor_beyond' => 0.50,

        // MaxBid = projectedValue14d / (1 + requiredROI) — the highest price
        // that still leaves at least this minimum return over 14 days.
        'required_roi' => (float) env('FANTASY_MARKET_BUY_REQUIRED_ROI', 0.05),

        // RecommendedBid estimates the likely winning bid from this league's
        // own auction history (median premium over market value paid by the
        // winner of past accepted market bids). Below this many samples the
        // history is too thin to trust, so a configurable flat premium is
        // used instead — never an invented "typical" figure dressed up as
        // real data. See MarketAuctionPremiumEstimator.
        'min_auction_history_samples' => (int) env('FANTASY_MARKET_BUY_MIN_HISTORY', 5),
        'fallback_winning_premium_pct' => (float) env('FANTASY_MARKET_BUY_FALLBACK_PREMIUM', 0.05),
    ],

    /*
    |--------------------------------------------------------------------------
    | Today ("Avui") — App\Services\Recommendation\TodayActionsService
    |--------------------------------------------------------------------------
    |
    | Purely an aggregator: every score/projection/decision it reads already
    | comes from PlayerDecisionEngine, MarketBuyAnalysisService or a stored
    | FantasyOffer/FantasyTeamPlayer field. This module only decides how to
    | rank and bucket what those already computed, never re-derives them.
    |
    */
    'today' => [
        // PriorityScore = these three weighted, clamped 0-100. Must sum to 1.0.
        'priority_weights' => [
            'urgency' => 0.45,
            'economic_impact' => 0.35,
            'confidence' => 0.20,
        ],

        // EconomicImpactRaw / currentMarketValue -> 0-100, piecewise-linear
        // through these [pct, score] points (ascending by pct), clamped at
        // both ends — a below-market or break-even outcome (<=0%) always
        // maps to the first point's score, 0.
        'economic_impact_scale' => [
            [0.00, 0],
            [0.02, 20],
            [0.05, 50],
            [0.10, 80],
            [0.15, 100],
        ],

        // Hour-based urgency bands, ascending by maxHours; a deadline beyond
        // the last band's maxHours uses the matching '..._beyond' value, and
        // a genuinely missing deadline never invents one of these numbers —
        // see TodayActionsService::urgencyFromHours().
        'clause_urgency_bands' => [
            ['maxHours' => 6, 'urgency' => 100],
            ['maxHours' => 12, 'urgency' => 95],
            ['maxHours' => 24, 'urgency' => 90],
            ['maxHours' => 48, 'urgency' => 60],
            ['maxHours' => 120, 'urgency' => 30],
        ],
        'clause_urgency_beyond' => 10,

        'offer_urgency_bands' => [
            ['maxHours' => 6, 'urgency' => 100],
            ['maxHours' => 12, 'urgency' => 90],
            ['maxHours' => 24, 'urgency' => 80],
            ['maxHours' => 48, 'urgency' => 50],
        ],
        'offer_urgency_beyond' => 30,

        'market_urgency_bands' => [
            ['maxHours' => 6, 'urgency' => 100],
            ['maxHours' => 12, 'urgency' => 90],
            ['maxHours' => 24, 'urgency' => 80],
            ['maxHours' => 48, 'urgency' => 50],
        ],
        'market_urgency_beyond' => 30,

        // A sell/trade candidate with no real offer and no deadline at all:
        // moderate-low, flat, regardless of how high the underlying score
        // is — a high Trade Score alone never implies "act today".
        'trade_no_deadline_urgency' => 25,

        // A candidate only enters PRIORITAT MÀXIMA if its urgency clears this.
        'urgent_urgency_threshold' => 80,

        // SEGUIMENT: a score in this range is "approaching" a real signal,
        // not there yet — not invented, this is the same range the spec
        // names for Trade Score / Buy Economic Score / clause score.
        'watch_score_range' => [60, 74],

        // PLANIFICAT only lists clause raises inside this many days — beyond
        // it, the same signal downgrades to SEGUIMENT instead of either
        // disappearing or saturating the planned list with far-future items.
        'planned_horizon_days' => 7,

        // Below this confidence, a would-be priority action is held back to
        // OPORTUNITATS instead — the data quality gate from section 8.
        'min_confidence_for_priority' => 40,
    ],
];
