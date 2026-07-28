<?php

namespace Tests\Feature\Federation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class WebFingerTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_it_resolves_a_local_account_by_acct_address(): void
    {
        $user = $this->createFullAccount('finger');
        $domain = config('openbook.domain');

        $response = $this->get('/.well-known/webfinger?resource='.urlencode("acct:finger@{$domain}"));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/jrd+json; charset=utf-8');
        $response->assertJson([
            'subject' => "acct:finger@{$domain}",
        ]);

        $links = $response->json('links');
        $self = collect($links)->firstWhere('rel', 'self');

        $this->assertSame('application/activity+json', $self['type']);
        $this->assertSame($user->actor->uri, $self['href']);
    }

    public function test_it_resolves_a_local_account_by_its_canonical_actor_url(): void
    {
        $user = $this->createFullAccount('fingerurl');

        $response = $this->get('/.well-known/webfinger?resource='.urlencode($user->actor->uri));

        $response->assertOk();
        $response->assertJson(['subject' => 'acct:'.$user->actor->handle()]);
    }

    public function test_it_returns_not_found_for_an_unknown_user(): void
    {
        $domain = config('openbook.domain');

        $this->get('/.well-known/webfinger?resource='.urlencode("acct:nessuno@{$domain}"))
            ->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_foreign_domain(): void
    {
        $this->createFullAccount('altrodominio');

        $this->get('/.well-known/webfinger?resource='.urlencode('acct:altrodominio@altro-server.example'))
            ->assertNotFound();
    }

    public function test_it_returns_not_found_without_a_resource_parameter(): void
    {
        $this->get('/.well-known/webfinger')->assertNotFound();
    }
}
