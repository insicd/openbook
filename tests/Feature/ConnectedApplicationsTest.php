<?php

namespace Tests\Feature;

use App\Domain\Accounts\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class ConnectedApplicationsTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function client(string $name = 'Example mobile app'): Client
    {
        return app(ClientRepository::class)->createAuthorizationCodeGrantClient($name, ['https://client.example/callback'], confidential: false);
    }

    private function access(User $user, Client $client, array $attributes = [], array $refresh = []): Token
    {
        $token = Token::create(array_merge([
            'id' => Str::random(80), 'user_id' => $user->id, 'client_id' => $client->id,
            'scopes' => ['api'], 'revoked' => false, 'expires_at' => now()->addYear(),
        ], $attributes));
        RefreshToken::create(array_merge([
            'id' => Str::random(80), 'access_token_id' => $token->id,
            'revoked' => false, 'expires_at' => now()->addYear(),
        ], $refresh));

        return $token;
    }

    public function test_guests_cannot_manage_connected_apps(): void
    {
        $this->get(route('settings.edit', ['tab' => 'applications']))->assertRedirect(route('login'));
        $this->delete(route('settings.applications.destroy', ['access' => Str::random(80)]))->assertRedirect(route('login'));
    }

    public function test_the_list_only_contains_the_users_available_accesses_and_keeps_each_access_separate(): void
    {
        $user = $this->createFullAccount('appowner');
        $other = $this->createFullAccount('otherowner');
        $client = $this->client();
        $first = $this->access($user, $client);
        $second = $this->access($user, $client);
        $renewable = $this->access($user, $this->client('Renewable app'), ['expires_at' => now()->subMinute()]);
        $this->access($other, $this->client('Other user app'));
        $this->access($user, $this->client('Closed app'), ['revoked' => true], ['revoked' => true]);
        $this->access($user, $this->client('Expired app'), ['expires_at' => now()->subDay()], ['expires_at' => now()->subDay()]);
        $revokedClient = $this->client('Unavailable app');
        $revokedClient->update(['revoked' => true]);
        $this->access($user, $revokedClient);

        $response = $this->actingAs($user)->get(route('settings.edit', ['tab' => 'applications']))->assertOk()
            ->assertSee(__('oauth.applications'))->assertSee(__('oauth.close_access'))
            ->assertDontSee('Other user app')->assertDontSee('Closed app')->assertDontSee('Expired app')->assertDontSee('Unavailable app');
        $this->assertEqualsCanonicalizing([$first->id, $second->id, $renewable->id], $response->viewData('connectedApplications')->pluck('id')->all());
        $this->assertSame(3, substr_count($response->getContent(), 'class="ob-card ob-connected-application"'));
    }

    public function test_closing_an_access_revokes_both_tokens_but_keeps_other_accesses_and_the_website_session(): void
    {
        $user = $this->createFullAccount('closeowner');
        $client = $this->client();
        $token = $this->access($user, $client);
        $otherAccess = $this->access($user, $client);
        $otherUser = $this->access($this->createFullAccount('untouched'), $client);

        $this->actingAs($user)->delete(route('settings.applications.destroy', ['access' => $token->id]))
            ->assertRedirect(route('settings.edit', ['tab' => 'applications']))->assertSessionHas('status', __('oauth.closed'));
        $this->assertTrue($token->fresh()->revoked);
        $this->assertTrue($token->refreshToken->revoked);
        $this->assertFalse($otherAccess->fresh()->revoked);
        $this->assertFalse($otherAccess->refreshToken->revoked);
        $this->assertFalse($otherUser->fresh()->revoked);
        $this->get(route('settings.edit', ['tab' => 'applications']))->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_another_users_access_cannot_be_closed(): void
    {
        $owner = $this->createFullAccount('privateowner');
        $token = $this->access($owner, $this->client());
        $this->actingAs($this->createFullAccount('intruder'))
            ->delete(route('settings.applications.destroy', ['access' => $token->id]))->assertNotFound();
        $this->assertFalse($token->fresh()->revoked);
        $this->assertFalse($token->refreshToken->revoked);
    }

    public function test_an_outdated_access_does_not_claim_to_close_a_replacement(): void
    {
        $user = $this->createFullAccount('renewedowner');
        $client = $this->client();
        $old = $this->access($user, $client, ['revoked' => true], ['revoked' => true]);
        $replacement = $this->access($user, $client);
        $this->actingAs($user)->delete(route('settings.applications.destroy', ['access' => $old->id]))
            ->assertSessionHas('status', __('oauth.list_changed'));
        $this->assertFalse($replacement->fresh()->revoked);
    }

    public function test_an_access_with_a_revoked_bearer_but_a_valid_refresh_can_still_be_closed(): void
    {
        $user = $this->createFullAccount('refreshowner');
        $token = $this->access($user, $this->client(), ['revoked' => true]);
        $this->actingAs($user)->get(route('settings.edit', ['tab' => 'applications']))->assertSee('Example mobile app');
        $this->delete(route('settings.applications.destroy', ['access' => $token->id]))->assertSessionHas('status', __('oauth.closed'));
        $this->assertTrue($token->refreshToken->revoked);
    }

    public function test_names_are_escaped_and_dates_follow_the_users_timezone(): void
    {
        $user = $this->createFullAccount('displayowner');
        $user->settings->update(['timezone' => 'Europe/Rome']);
        $token = $this->access($user, $this->client('<script>bad()</script>'), ['created_at' => '2026-01-15 12:00:00']);
        $expectedTime = $token->created_at->copy()->setTimezone('Europe/Rome')->format('H:i');
        $response = $this->actingAs($user)->get(route('settings.edit', ['tab' => 'applications']))->assertOk()
            ->assertSee('<script>bad()</script>')->assertDontSee('<script>bad()</script>', false)->assertSee($expectedTime);
        $this->assertStringNotContainsString('refresh_token', $response->getContent());
        $this->assertStringNotContainsString('client_id', $response->getContent());
    }

    public function test_the_list_is_paginated_and_has_an_empty_state(): void
    {
        $user = $this->createFullAccount('pageowner');
        $this->actingAs($user)->get(route('settings.edit', ['tab' => 'applications']))->assertSee(__('oauth.no_applications'));
        $client = $this->client();
        for ($i = 0; $i < 21; $i++) {
            $this->access($user, $client);
        }
        $first = $this->get(route('settings.edit', ['tab' => 'applications']))->assertOk()->viewData('connectedApplications');
        $this->assertCount(20, $first->items());
        $this->assertStringContainsString('tab=applications', $first->nextPageUrl());
        $second = $this->get($first->nextPageUrl())->assertOk()->viewData('connectedApplications');
        $this->assertCount(1, $second->items());
    }

    public function test_production_lists_and_revokes_connected_accesses_with_csrf(): void
    {
        $user = $this->createFullAccount('productionowner');
        $token = $this->access($user, $this->client());
        $this->app->instance('env', 'production');
        $this->actingAs($user)->get(route('settings.edit', ['tab' => 'applications']))
            ->assertOk()->assertViewHas('activeTab', 'applications')->assertSee(__('oauth.applications'));
        $this->withSession(['_token' => 'production-csrf'])
            ->delete(route('settings.applications.destroy', ['access' => $token->id]))->assertStatus(419);
        $this->assertFalse($token->fresh()->revoked);
        $this->withSession(['_token' => 'production-csrf'])
            ->delete(route('settings.applications.destroy', ['access' => $token->id]), ['_token' => 'production-csrf'])->assertRedirect(route('settings.edit', ['tab' => 'applications']));
        $this->assertTrue($token->fresh()->revoked);
        $this->assertTrue($token->refreshToken->revoked);
    }
}
