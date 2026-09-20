<?php

namespace Tests\Feature\Federation;

use App\Application\Services\InstanceRelayActor;
use App\Domain\Posts\ContentParser;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class RelayActorTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

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
            ->assertJsonPath('followers', url('/relay/followers'))
            ->assertJsonPath('following', url('/relay/following'))
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
            ->assertJsonPath('first', url('/relay/outbox?page=1'));

        $this->assertDatabaseCount('actors', 1);
    }

    public function test_relay_actor_is_not_exposed_as_a_regular_profile(): void
    {
        $this->getJson('/relay')->assertOk();

        $this->get('/@relay')->assertNotFound();
        $this->getJson('/users/relay/outbox')->assertNotFound();
        $this->assertTrue(app(ContentParser::class)->extractMentionedActors('Ciao @relay')->isEmpty());
    }

    public function test_relay_outbox_announces_public_local_content_only(): void
    {
        $local = $this->createFullAccount('outboxlocale');
        $remote = $this->createRemoteActor('outboxremoto');
        $public = Post::query()->create([
            'actor_id' => $local->actor->id,
            'body' => 'Pubblico.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        Post::query()->create([
            'actor_id' => $local->actor->id,
            'body' => 'Non elencato.',
            'visibility' => Post::VISIBILITY_UNLISTED,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        Post::query()->create([
            'actor_id' => $remote->id,
            'uri' => $remote->uri.'/statuses/1',
            'body' => 'Remoto.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $this->getJson('/relay/outbox', ['Accept' => 'application/activity+json'])
            ->assertOk()
            ->assertJsonPath('totalItems', 1)
            ->assertJsonPath('first', url('/relay/outbox?page=1'));

        $this->getJson('/relay/outbox?page=1', ['Accept' => 'application/activity+json'])
            ->assertOk()
            ->assertJsonPath('type', 'OrderedCollectionPage')
            ->assertJsonCount(1, 'orderedItems')
            ->assertJsonPath('orderedItems.0.type', 'Announce')
            ->assertJsonPath('orderedItems.0.actor', url('/relay'))
            ->assertJsonPath('orderedItems.0.object', url('/posts/'.$public->id));
    }

    public function test_relay_followers_collection_contains_accepted_application_followers(): void
    {
        $serviceActor = app(InstanceRelayActor::class)->getOrCreate();
        $accepted = $this->createRemoteActor('accepted', 'relay.example', [
            'type' => Actor::TYPE_APPLICATION,
        ]);
        $pending = $this->createRemoteActor('pending', 'relay.example', [
            'type' => Actor::TYPE_APPLICATION,
        ]);
        foreach ([[$accepted, Follow::STATUS_ACCEPTED], [$pending, Follow::STATUS_PENDING]] as [$actor, $status]) {
            Follow::query()->create([
                'follower_id' => $actor->id,
                'following_id' => $serviceActor->id,
                'status' => $status,
                'requested_at' => now(),
                'accepted_at' => $status === Follow::STATUS_ACCEPTED ? now() : null,
            ]);
        }

        $this->getJson('/relay/followers', ['Accept' => 'application/activity+json'])
            ->assertOk()
            ->assertJsonPath('totalItems', 1);
        $this->getJson('/relay/followers?page=1', ['Accept' => 'application/activity+json'])
            ->assertOk()
            ->assertJsonPath('orderedItems.0', $accepted->uri)
            ->assertJsonCount(1, 'orderedItems');
    }
}
