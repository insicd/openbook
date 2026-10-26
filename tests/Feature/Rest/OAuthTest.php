<?php

namespace Tests\Feature\Rest;

use App\Domain\Accounts\User;
use App\Http\Middleware\EnsureRestOAuthRequest;
use App\Providers\RestAuthServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Bridge\AccessToken;
use Laravel\Passport\Bridge\Client as BridgeClient;
use Laravel\Passport\Client;
use Laravel\Passport\Token;
use League\OAuth2\Server\CryptKey;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\UsesPassportKeys;
use Tests\TestCase;

class OAuthTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase, UsesPassportKeys;

    private Client $client;

    private string $verifier;

    private string $state;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        $registration = $this->postJson('/oauth/register', [
            'client_name' => 'Example mobile app',
            'redirect_uris' => ['https://client.example/callback'],
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ])->assertCreated()->assertJsonMissingPath('client_secret');
        $this->client = Client::findOrFail($registration->json('client_id'));
        $this->verifier = Str::random(64);
        $this->state = Str::random(32);
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null): TestResponse
    {
        // Simulate independent HTTP requests: do not reuse an authenticated guard.
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function authorizationParameters(array $overrides = []): array
    {
        return array_merge([
            'client_id' => $this->client->id, 'redirect_uri' => 'https://client.example/callback',
            'response_type' => 'code', 'state' => $this->state, 'prompt' => 'consent',
            'code_challenge_method' => 'S256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '='),
        ], $overrides);
    }

    private function login(User $user): void
    {
        $this->post(route('login'), ['login' => $user->username, 'password' => 'Password123'])->assertRedirect();
    }

    private function code(User $user): string
    {
        $this->login($user);
        $page = $this->get('/oauth/authorize?'.http_build_query($this->authorizationParameters()))->assertOk()->assertViewIs('auth.oauth.authorize');
        $response = $this->post('/oauth/authorize', [
            'state' => $this->state, 'client_id' => $this->client->id,
            'auth_token' => $page->viewData('authToken'),
        ])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame($this->state, $query['state']);
        $this->assertArrayNotHasKey('access_token', $query);

        return $query['code'];
    }

    private function exchange(string $code, array $overrides = []): TestResponse
    {
        return $this->post('/oauth/token', array_merge([
            'grant_type' => 'authorization_code', 'client_id' => $this->client->id,
            'redirect_uri' => 'https://client.example/callback', 'code' => $code,
            'code_verifier' => $this->verifier,
        ], $overrides), ['Accept' => 'application/json']);
    }

    public function test_account_checks_run_after_the_browser_session_has_started(): void
    {
        $router = $this->app['router'];
        $route = $router->getRoutes()->getByName('passport.authorizations.authorize');
        $middleware = $router->gatherRouteMiddleware($route);
        $session = array_search(StartSession::class, $middleware, true);
        $accountCheck = array_search(EnsureRestOAuthRequest::class, $middleware, true);
        $this->assertNotFalse($session);
        $this->assertNotFalse($accountCheck);
        $this->assertLessThan($accountCheck, $session);
    }

    public function test_the_existing_login_page_returns_to_authorization(): void
    {
        $user = $this->createFullAccount('oauthlogin');
        $url = '/oauth/authorize?'.http_build_query($this->authorizationParameters());
        $this->get($url)->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk()->assertViewIs('auth.login');
        $login = $this->post(route('login'), ['login' => $user->username, 'password' => 'Password123'])->assertRedirect();
        parse_str(parse_url($login->headers->get('Location'), PHP_URL_QUERY), $returned);
        $this->assertEquals($this->authorizationParameters(), $returned);
        $this->get($url)->assertOk()->assertSee('Example mobile app')->assertSee($user->federatedHandle());
    }

    public function test_pkce_exchange_returns_standard_tokens_for_all_rest_endpoints(): void
    {
        $user = $this->createFullAccount('oauthperson');
        $code = $this->code($user);
        $response = $this->exchange($code)->assertOk()->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'refresh_token']);
        $this->assertSame('Bearer', $response->json('token_type'));
        $this->assertGreaterThan(360 * 86400, $response->json('expires_in'));
        $this->assertLessThan(367 * 86400, $response->json('expires_in'));
        $token = $response->json('access_token');
        $this->withToken($token)->get('/rest/v1/me')->assertOk()->assertJsonPath('myPerson.personId', $user->actor->id);
        $this->assertCount(3, explode('.', $token));
        $this->assertDatabaseHas('oauth_access_tokens', ['user_id' => $user->id, 'client_id' => $this->client->id]);
        foreach (['', '/followers', '/following'] as $suffix) {
            $this->withToken($token)->get('/rest/v1/persons/'.$user->actor->id.$suffix)->assertOk();
        }
        $this->exchange($code)->assertStatus(400);
    }

    public function test_guests_web_sessions_and_invalid_bearers_cannot_read_rest(): void
    {
        $user = $this->createFullAccount('oauthguest');
        $this->get('/rest/v1/me')->assertUnauthorized()->assertHeader('Content-Type', 'application/problem+json');
        foreach (['', '/followers', '/following'] as $suffix) {
            $this->get('/rest/v1/persons/'.$user->actor->id.$suffix)->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer')
                ->assertHeader('Content-Type', 'application/problem+json');
        }
        $this->login($user);
        $this->get('/rest/v1/me')->assertUnauthorized();
        $this->get('/rest/v1/persons/'.$user->actor->id)->assertUnauthorized();
        $this->withToken('invalid')->get('/rest/v1/persons/'.$user->actor->id)->assertUnauthorized();
        $this->get('/rest/v1/me')->assertUnauthorized();
    }

    public function test_me_checks_revocation_and_account_status_before_cache_revalidation(): void
    {
        $user = $this->createFullAccount('oauthme');
        $tokens = $this->exchange($this->code($user))->assertOk()->json();
        $response = $this->withToken($tokens['access_token'])->get('/rest/v1/me')->assertOk();
        $this->withHeader('If-None-Match', $response->headers->get('ETag'))->get('/rest/v1/me')->assertStatus(304);
        Token::query()->where('user_id', $user->id)->update(['revoked' => true]);
        $this->get('/rest/v1/me')->assertUnauthorized();
        Token::query()->where('user_id', $user->id)->update(['revoked' => false]);
        $user->update(['status' => User::STATUS_SUSPENDED]);
        $this->get('/rest/v1/me')->assertForbidden();
    }

    public function test_configured_duration_applies_to_access_refresh_and_renewed_tokens(): void
    {
        config(['openbook.oauth_token_duration' => 7]);
        (new RestAuthServiceProvider($this->app))->boot();
        $user = $this->createFullAccount('oauthduration');
        $response = $this->exchange($this->code($user))->assertOk();
        $this->assertEqualsWithDelta(7 * 86400, $response->json('expires_in'), 3605);
        $first = Token::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $first->expires_at->timestamp, 5);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $first->refreshToken->expires_at->timestamp, 5);
        $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $this->client->id,
            'refresh_token' => $response->json('refresh_token')])->assertOk();
        $next = Token::query()->where('user_id', $user->id)->where('revoked', false)->firstOrFail();
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $next->expires_at->timestamp, 5);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $next->refreshToken->expires_at->timestamp, 5);
    }

    public static function invalidDurations(): array
    {
        return [[''], [null], [0], [-1], ['invalid'], ['1.5'], [true], [false]];
    }

    #[DataProvider('invalidDurations')]
    public function test_invalid_duration_falls_back_to_365_days(mixed $duration): void
    {
        config(['openbook.oauth_token_duration' => $duration]);
        (new RestAuthServiceProvider($this->app))->boot();
        $user = $this->createFullAccount('oauthfallback');
        $this->exchange($this->code($user))->assertOk();
        $token = Token::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertEqualsWithDelta(now()->addDays(365)->timestamp, $token->expires_at->timestamp, 5);
        $this->assertEqualsWithDelta(now()->addDays(365)->timestamp, $token->refreshToken->expires_at->timestamp, 5);
    }

    public function test_consent_can_be_denied(): void
    {
        $user = $this->createFullAccount('oauthdeny');
        $this->login($user);
        $page = $this->get('/oauth/authorize?'.http_build_query($this->authorizationParameters()))->assertOk();
        $response = $this->delete('/oauth/authorize', ['state' => $this->state, 'client_id' => $this->client->id,
            'auth_token' => $page->viewData('authToken')])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('access_denied', $query['error']);
        $this->assertSame($this->state, $query['state']);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }

    public function test_pkce_redirects_and_grant_types_are_checked(): void
    {
        $user = $this->createFullAccount('oauthinvalid');
        $this->login($user);
        foreach ([['code_challenge_method' => 'plain'], ['response_type' => 'token'], ['code_challenge' => '']] as $overrides) {
            $this->get('/oauth/authorize?'.http_build_query($this->authorizationParameters($overrides)))
                ->assertStatus(400)->assertHeaderMissing('Location');
        }
        $this->get('/oauth/authorize?'.http_build_query($this->authorizationParameters(['redirect_uri' => 'https://attacker.example/callback'])))
            ->assertStatus(401)->assertHeaderMissing('Location');
        $this->exchange($this->code($user), ['code_verifier' => Str::random(64)])->assertStatus(400);
        foreach (['password', 'client_credentials', 'personal_access'] as $grant) {
            $this->post('/oauth/token', ['grant_type' => $grant])->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');
        }
    }

    public function test_inactive_accounts_cannot_authorize_exchange_or_refresh(): void
    {
        $user = $this->createFullAccount('oauthstatus');
        $tokens = $this->exchange($this->code($user))->assertOk()->json();
        $user->update(['status' => User::STATUS_SUSPENDED]);
        $this->withToken($tokens['access_token'])->get('/rest/v1/persons/'.$user->actor->id)->assertForbidden();
        $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $this->client->id,
            'refresh_token' => $tokens['refresh_token']])->assertStatus(401);
        $this->withoutHeader('Authorization')->get('/oauth/authorize?'.http_build_query($this->authorizationParameters()))->assertForbidden();
        $user->update(['status' => User::STATUS_ACTIVE]);
        $code = $this->code($user);
        $user->update(['status' => User::STATUS_DISABLED]);
        $this->exchange($code)->assertStatus(401);
    }

    public function test_revoked_expired_and_tampered_tokens_cannot_validate_a_cache_entry(): void
    {
        $user = $this->createFullAccount('oauthcache');
        $tokens = $this->exchange($this->code($user))->assertOk()->json();
        $url = '/rest/v1/persons/'.$user->actor->id;
        $first = $this->withToken($tokens['access_token'])->get($url)->assertOk();
        $this->withHeader('If-None-Match', $first->headers->get('ETag'))->get($url)->assertStatus(304);
        Token::query()->where('user_id', $user->id)->update(['revoked' => true]);
        $this->get($url)->assertUnauthorized();
        $this->withToken(substr($tokens['access_token'], 0, -6).'abcdef')->get($url)->assertUnauthorized();
        $this->withoutHeader('Authorization')->get($url)->assertUnauthorized();
        $record = Token::query()->where('user_id', $user->id)->firstOrFail();
        $record->update(['revoked' => false]);
        $expired = new AccessToken($user->id, [], new BridgeClient($this->client->id));
        $expired->setIdentifier($record->id);
        $expired->setExpiryDateTime(now()->subMinute()->toDateTimeImmutable());
        $expired->setPrivateKey(new CryptKey(config('passport.private_key')));
        $this->withToken($expired->toString())->get($url)->assertUnauthorized();
    }

    public function test_refresh_works_without_another_login(): void
    {
        $user = $this->createFullAccount('oauthrefresh');
        $tokens = $this->exchange($this->code($user))->assertOk()->json();
        $next = $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $this->client->id,
            'refresh_token' => $tokens['refresh_token']])->assertOk()->json();
        $this->assertNotSame($tokens['access_token'], $next['access_token']);
        $this->withToken($next['access_token'])->get('/rest/v1/persons/'.$user->actor->id)->assertOk();
        $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $this->client->id,
            'refresh_token' => $tokens['refresh_token']])->assertStatus(400);
    }

    public function test_closing_an_access_from_settings_blocks_rest_and_refresh_but_keeps_other_accesses(): void
    {
        $user = $this->createFullAccount('oauthclose');
        $tokens = $this->exchange($this->code($user))->assertOk()->json();
        $first = Token::query()->where('user_id', $user->id)->firstOrFail();
        $other = $this->exchange($this->code($user))->assertOk()->json();
        $this->delete(route('settings.applications.destroy', ['access' => $first->id]))
            ->assertRedirect(route('settings.edit', ['tab' => 'applications']));
        $this->withToken($tokens['access_token'])->get('/rest/v1/persons/'.$user->actor->id)->assertUnauthorized();
        $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $this->client->id,
            'refresh_token' => $tokens['refresh_token']])->assertStatus(400);
        $this->withToken($other['access_token'])->get('/rest/v1/persons/'.$user->actor->id)->assertOk();
        $this->withoutHeader('Authorization')->get(route('settings.edit', ['tab' => 'applications']))->assertOk();
    }

    public function test_metadata_registration_and_cors_are_standard(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
            ->assertJsonPath('code_challenge_methods_supported', ['S256'])
            ->assertJsonPath('registration_endpoint', rtrim(config('app.url'), '/').'/oauth/register');
        $this->postJson('/oauth/register', ['redirect_uris' => ['com.example.app:/callback'],
            'token_endpoint_auth_method' => 'none'])->assertCreated()->assertJsonMissingPath('client_secret');
        foreach (['https://client.example/callback#fragment', 'http://public.example/callback', 'javascript:alert(1)', 'https://user:secret@client.example/callback'] as $uri) {
            $this->postJson('/oauth/register', ['redirect_uris' => [$uri], 'token_endpoint_auth_method' => 'none'])
                ->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        }
        $this->postJson('/oauth/register', ['redirect_uris' => ['https://client.example/callback'],
            'token_endpoint_auth_method' => 'client_secret_basic'])->assertStatus(400)->assertJsonPath('error', 'invalid_client_metadata');
        $this->options('/oauth/token', [], ['Origin' => 'https://client.example', 'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type'])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', '*');
        $this->get('/rest/v1/persons/'.Str::uuid(), ['Origin' => 'https://client.example'])->assertUnauthorized()
            ->assertHeader('Access-Control-Allow-Origin', '*')->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    public function test_registration_rejects_objects_where_json_lists_are_required(): void
    {
        $valid = ['redirect_uris' => ['https://client.example/callback'], 'token_endpoint_auth_method' => 'none'];
        foreach ([
            ['redirect_uris' => ['callback' => 'https://client.example/callback']],
            ['grant_types' => ['grant' => 'authorization_code']],
            ['response_types' => ['response' => 'code']],
        ] as $metadata) {
            $this->postJson('/oauth/register', array_merge($valid, $metadata))->assertStatus(400);
        }
        $this->assertDatabaseCount('oauth_clients', 1);
    }

    public function test_environment_guards_apply_even_with_cached_routes(): void
    {
        $this->app->instance('env', 'production');
        $this->get('/oauth/authorize?'.http_build_query($this->authorizationParameters()))->assertNotFound();
        $this->post('/oauth/token', ['grant_type' => 'authorization_code'])->assertNotFound();
        $this->postJson('/oauth/register', [])->assertNotFound();
        $this->get('/.well-known/oauth-authorization-server')->assertNotFound();
    }
}
