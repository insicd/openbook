<?php

namespace Tests\Feature\Federation;

use App\Domain\Posts\ContentParser;
use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelayActorTest extends TestCase
{
    use RefreshDatabase;

    public function test_relay_actor_is_exposed_as_an_application(): void
    {
        $response = $this->getJson('/relay', ['Accept' => 'application/activity+json']);

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/activity+json; charset=utf-8')
            ->assertJsonPath('id', url('/relay'))
            ->assertJsonPath('type', 'Application')
            ->assertJsonPath('preferredUsername', 'relay')
            ->assertJsonPath('inbox', url('/inbox'))
            ->assertJsonPath('outbox', url('/relay/outbox'))
            ->assertJsonPath('endpoints.sharedInbox', url('/inbox'));

        $this->assertNotEmpty($response->json('publicKey.publicKeyPem'));
        $this->assertDatabaseHas('actors', [
            'type' => Actor::TYPE_APPLICATION,
            'preferred_username' => 'relay',
            'is_local' => true,
        ]);
    }

    public function test_relay_actor_has_webfinger_and_an_empty_outbox(): void
    {
        $domain = (string) config('openbook.domain');

        $this->getJson('/.well-known/webfinger?resource='.urlencode('acct:relay@'.$domain))
            ->assertOk()
            ->assertJsonPath('subject', 'acct:relay@'.$domain)
            ->assertJsonPath('links.0.href', url('/relay'));

        $this->getJson('/relay/outbox', ['Accept' => 'application/activity+json'])
            ->assertOk()
            ->assertJsonPath('type', 'OrderedCollection')
            ->assertJsonPath('totalItems', 0)
            ->assertJsonCount(0, 'orderedItems');

        $this->assertDatabaseCount('actors', 1);
    }

    public function test_relay_actor_is_not_exposed_as_a_regular_profile(): void
    {
        $this->getJson('/relay')->assertOk();

        $this->get('/@relay')->assertNotFound();
        $this->getJson('/users/relay/outbox')->assertNotFound();
        $this->assertTrue(app(ContentParser::class)->extractMentionedActors('Ciao @relay')->isEmpty());
    }
}
