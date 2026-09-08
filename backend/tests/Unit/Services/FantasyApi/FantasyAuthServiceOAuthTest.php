<?php

namespace Tests\Unit\Services\FantasyApi;

use App\Models\FantasyAccount;
use App\Models\User;
use App\Services\FantasyApi\Exceptions\FantasyApiAuthenticationException;
use App\Services\FantasyApi\FantasyAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FantasyAuthServiceOAuthTest extends TestCase
{
    use RefreshDatabase;

    private function account(): FantasyAccount
    {
        return FantasyAccount::create(['user_id' => User::factory()->create()->id]);
    }

    public function test_start_interactive_login_builds_the_real_authorize_url_with_pkce(): void
    {
        $account = $this->account();
        $url = (new FantasyAuthService)->startInteractiveLogin($account);

        $this->assertStringStartsWith(config('fantasy.auth.authorize_endpoint'), $url);
        $this->assertStringContainsString('client_id='.config('fantasy.auth.oauth_client_id'), $url);
        $this->assertStringContainsString('redirect_uri='.urlencode(config('fantasy.auth.oauth_redirect_uri')), $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
        $this->assertStringContainsString('response_type=code', $url);
    }

    public function test_finish_interactive_login_extracts_the_code_from_a_full_redirect_url_and_stores_tokens(): void
    {
        Http::fake([
            'login.laliga.es/*' => Http::response([
                'access_token' => 'brand-new-access-token',
                'refresh_token' => 'brand-new-refresh-token',
                'expires_in' => 86400,
            ], 200),
        ]);

        $account = $this->account();
        $auth = new FantasyAuthService;
        $authorizeUrl = $auth->startInteractiveLogin($account);
        parse_str(parse_url($authorizeUrl, PHP_URL_QUERY), $authorizeParams);

        $auth->finishInteractiveLogin(
            $account,
            'authredirect://com.lfp.laligafantasy?code=abc123&state='.$authorizeParams['state'],
        );

        $fresh = $account->fresh();
        $this->assertSame('brand-new-access-token', $fresh->access_token);
        $this->assertSame('brand-new-refresh-token', $fresh->refresh_token);
        $this->assertSame(config('fantasy.auth.oauth_client_id'), $fresh->token_client_id);

        Http::assertSent(function (Request $r) {
            return $r['grant_type'] === 'authorization_code'
                && $r['code'] === 'abc123'
                && $r['client_id'] === config('fantasy.auth.oauth_client_id')
                && $r['redirect_uri'] === config('fantasy.auth.oauth_redirect_uri')
                && ! empty($r['code_verifier']);
        });
    }

    public function test_finish_interactive_login_accepts_a_bare_code(): void
    {
        Http::fake(['login.laliga.es/*' => Http::response(['access_token' => 'x', 'expires_in' => 3600], 200)]);

        $account = $this->account();
        $auth = new FantasyAuthService;
        $auth->startInteractiveLogin($account);
        $auth->finishInteractiveLogin($account, '  "justthiscode"  ');

        Http::assertSent(fn (Request $r) => $r['code'] === 'justthiscode');
    }

    public function test_finish_interactive_login_without_starting_first_throws(): void
    {
        Http::fake();

        $account = $this->account();

        $this->expectException(FantasyApiAuthenticationException::class);
        (new FantasyAuthService)->finishInteractiveLogin($account, 'authredirect://com.lfp.laligafantasy?code=abc');

        Http::assertNothingSent();
    }

    public function test_finish_interactive_login_rejects_a_code_with_no_state_match(): void
    {
        Http::fake();

        $account = $this->account();
        $auth = new FantasyAuthService;
        $auth->startInteractiveLogin($account);

        $this->expectException(FantasyApiAuthenticationException::class);
        $auth->finishInteractiveLogin($account, 'authredirect://com.lfp.laligafantasy?code=abc&state=not-the-real-one');
    }

    public function test_finish_interactive_login_with_no_code_throws(): void
    {
        Http::fake();

        $account = $this->account();
        $auth = new FantasyAuthService;
        $auth->startInteractiveLogin($account);

        $this->expectException(FantasyApiAuthenticationException::class);
        $auth->finishInteractiveLogin($account, 'authredirect://com.lfp.laligafantasy?state=abc');
    }

    public function test_refresh_uses_the_oauth_client_id_when_tokens_came_from_interactive_login(): void
    {
        Http::fake(['login.laliga.es/*' => Http::response(['access_token' => 'refreshed', 'expires_in' => 3600], 200)]);

        $account = FantasyAccount::create([
            'user_id' => User::factory()->create()->id,
            'access_token' => 'old',
            'refresh_token' => 'old-refresh',
            'token_client_id' => config('fantasy.auth.oauth_client_id'),
            'token_expires_at' => now()->addHours(12),
        ]);

        (new FantasyAuthService)->refresh($account);

        Http::assertSent(fn (Request $r) => $r['client_id'] === config('fantasy.auth.oauth_client_id'));
    }

    public function test_refresh_falls_back_to_the_web_client_id_for_manually_pasted_tokens(): void
    {
        Http::fake(['login.laliga.es/*' => Http::response(['access_token' => 'refreshed', 'expires_in' => 3600], 200)]);

        $account = FantasyAccount::create([
            'user_id' => User::factory()->create()->id,
            'access_token' => 'old',
            'refresh_token' => 'old-refresh',
            'token_client_id' => null,
            'token_expires_at' => now()->addHours(12),
        ]);

        (new FantasyAuthService)->refresh($account);

        Http::assertSent(fn (Request $r) => $r['client_id'] === config('fantasy.auth.refresh_client_id'));
    }
}
