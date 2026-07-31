<?php

namespace Tests\Feature\Federation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class ActorContentNegotiationTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_a_normal_browser_request_still_receives_html(): void
    {
        $this->createFullAccount('htmluser');

        $response = $this->get('/@htmluser');

        $response->assertOk();
        $response->assertSee('htmluser');
        $this->assertStringStartsWith('text/html', $response->headers->get('Content-Type'));
    }

    public function test_an_activity_json_request_receives_the_person_document(): void
    {
        $user = $this->createFullAccount('apuser');

        $response = $this->get('/@apuser', ['Accept' => 'application/activity+json']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/activity+json; charset=utf-8');

        $response->assertJson([
            'id' => $user->actor->uri,
            'type' => 'Person',
            'preferredUsername' => 'apuser',
            'inbox' => url('/users/apuser/inbox'),
            'outbox' => url('/users/apuser/outbox'),
            'followers' => url('/users/apuser/followers'),
            'following' => url('/users/apuser/following'),
        ]);

        $response->assertJsonPath('publicKey.owner', $user->actor->uri);
        $response->assertJsonPath('publicKey.id', $user->actor->uri.'#main-key');
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $response->json('publicKey.publicKeyPem'));
    }

    public function test_a_ld_json_request_also_receives_the_person_document(): void
    {
        $this->createFullAccount('lduser');

        $response = $this->get('/@lduser', ['Accept' => 'application/ld+json']);

        $response->assertOk();
        $response->assertJsonPath('type', 'Person');
    }

    public function test_stale_endpoint_hosts_in_the_database_are_not_advertised(): void
    {
        $user = $this->createFullAccount('stalehost');
        $user->actor->endpoints->forceFill([
            'inbox' => 'https://old.example/users/stalehost/inbox',
            'outbox' => 'https://old.example/users/stalehost/outbox',
            'followers' => 'https://old.example/users/stalehost/followers',
            'following' => 'https://old.example/users/stalehost/following',
            'shared_inbox' => 'https://old.example/inbox',
        ])->saveQuietly();

        $response = $this->get('/@stalehost', ['Accept' => 'application/activity+json']);

        $response->assertOk();
        $response->assertJsonPath('inbox', url('/users/stalehost/inbox'));
        $response->assertJsonPath('endpoints.sharedInbox', url('/inbox'));
        $this->assertStringNotContainsString('old.example', (string) $response->getContent());
    }
}
