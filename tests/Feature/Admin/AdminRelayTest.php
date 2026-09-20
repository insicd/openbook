<?php

namespace Tests\Feature\Admin;

use App\Domain\Federation\Relay;
use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class AdminRelayTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_admin_page_creates_the_local_application_actor_once(): void
    {
        $admin = $this->createFullAccount('relayadmin');
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)->get(route('admin.relays.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.relays.index'))->assertOk();

        $actor = Actor::query()->where('type', Actor::TYPE_APPLICATION)->firstOrFail();

        $this->assertSame('relay', $actor->preferred_username);
        $this->assertFalse($actor->discoverable);
        $this->assertFalse($actor->indexable);
        $this->assertNotNull($actor->key?->private_key);
        $this->assertSame(url('/inbox'), $actor->endpoints?->inbox);
        $this->assertDatabaseCount('actors', 2);
    }

    public function test_admin_can_store_and_remove_an_inactive_mastodon_relay(): void
    {
        $admin = $this->createFullAccount('relayowner');
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)
            ->post(route('admin.relays.store'), [
                'protocol' => Relay::PROTOCOL_MASTODON,
                'inbox_url' => 'https://Relay.Example:443/inbox/',
                'receive_enabled' => '1',
            ])
            ->assertRedirect();

        $relay = Relay::query()->firstOrFail();
        $this->assertSame('https://relay.example/inbox', $relay->inbox_url);
        $this->assertSame(Relay::STATE_IDLE, $relay->state);
        $this->assertTrue($relay->receive_enabled);
        $this->assertFalse($relay->publish_enabled);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'relay.create']);

        $this->actingAs($admin)
            ->delete(route('admin.relays.destroy', $relay))
            ->assertRedirect();

        $this->assertDatabaseCount('relays', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'relay.delete']);
    }

    public function test_relay_endpoint_must_be_https_and_unique(): void
    {
        $admin = $this->createFullAccount('relayvalidation');
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)
            ->from(route('admin.relays.index'))
            ->post(route('admin.relays.store'), [
                'protocol' => Relay::PROTOCOL_MASTODON,
                'inbox_url' => 'http://relay.example/inbox',
            ])
            ->assertRedirect(route('admin.relays.index'))
            ->assertSessionHasErrors('inbox_url');

        $payload = [
            'protocol' => Relay::PROTOCOL_MASTODON,
            'inbox_url' => 'https://relay.example/inbox',
        ];

        $this->actingAs($admin)->post(route('admin.relays.store'), $payload)->assertRedirect();
        $this->actingAs($admin)->post(route('admin.relays.store'), $payload)->assertSessionHasErrors('inbox_url');
        $this->assertDatabaseCount('relays', 1);
    }

    public function test_admin_can_configure_an_actor_relay_from_its_actor_uri(): void
    {
        $admin = $this->createFullAccount('actorrelayowner');
        $admin->forceFill(['is_admin' => true])->save();
        $remote = $this->createRemoteActor('relay', 'events.example', [
            'type' => Actor::TYPE_APPLICATION,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.relays.store'), [
                'protocol' => Relay::PROTOCOL_ACTOR,
                'inbox_url' => $remote->uri,
                'receive_enabled' => '1',
            ])
            ->assertRedirect();

        $relay = Relay::query()->firstOrFail();
        $this->assertSame(Relay::PROTOCOL_ACTOR, $relay->protocol);
        $this->assertSame($remote->uri, $relay->actor_uri);
        $this->assertSame($remote->endpoints->inbox, $relay->inbox_url);
    }

    public function test_admin_can_discover_an_actor_relay_from_its_federated_identity(): void
    {
        $admin = $this->createFullAccount('actorrelayhandle');
        $admin->forceFill(['is_admin' => true])->save();
        $actorUri = 'https://events.example/relay';

        Http::fake([
            'https://events.example/.well-known/webfinger*' => Http::response([
                'subject' => 'acct:relay@events.example',
                'links' => [
                    ['rel' => 'self', 'type' => 'application/activity+json', 'href' => $actorUri],
                ],
            ], 200, ['Content-Type' => 'application/jrd+json']),
            $actorUri => Http::response([
                'id' => $actorUri,
                'type' => 'Application',
                'preferredUsername' => 'relay',
                'name' => 'Events relay',
                'inbox' => 'https://events.example/inbox',
                'outbox' => 'https://events.example/@relay/outbox',
                'followers' => 'https://events.example/@relay/followers',
                'following' => 'https://events.example/@relay/following',
                'publicKey' => [
                    'id' => $actorUri.'#main-key',
                    'owner' => $actorUri,
                    'publicKeyPem' => '-----BEGIN PUBLIC KEY-----test-----END PUBLIC KEY-----',
                ],
            ], 200, ['Content-Type' => 'application/activity+json']),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.relays.store'), [
                'protocol' => Relay::PROTOCOL_ACTOR,
                'inbox_url' => '@relay@events.example',
                'receive_enabled' => '1',
            ])
            ->assertRedirect();

        $relay = Relay::query()->firstOrFail();
        $this->assertSame($actorUri, $relay->actor_uri);
        $this->assertSame('https://events.example/inbox', $relay->inbox_url);
    }

    public function test_admin_can_update_relay_directions_without_recreating_it(): void
    {
        $admin = $this->createFullAccount('relaydirections');
        $admin->forceFill(['is_admin' => true])->save();
        $relay = Relay::query()->create([
            'protocol' => Relay::PROTOCOL_MASTODON,
            'inbox_url' => 'https://relay.example/inbox',
            'inbox_url_hash' => hash('sha256', 'https://relay.example/inbox'),
            'state' => Relay::STATE_ACCEPTED,
            'receive_enabled' => true,
            'publish_enabled' => true,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.relays.update', $relay), ['receive_enabled' => '1'])
            ->assertRedirect();

        $relay->refresh();
        $this->assertTrue($relay->receive_enabled);
        $this->assertFalse($relay->publish_enabled);
        $this->assertDatabaseHas('audit_logs', ['action' => 'relay.update']);
    }

    public function test_moderator_cannot_manage_relays(): void
    {
        $moderator = $this->createFullAccount('relaymod');
        $moderator->forceFill(['is_moderator' => true])->save();

        $this->actingAs($moderator)->get(route('admin.relays.index'))->assertForbidden();
        $this->actingAs($moderator)->post(route('admin.relays.store'), [
            'protocol' => Relay::PROTOCOL_MASTODON,
            'inbox_url' => 'https://relay.example/inbox',
        ])->assertForbidden();
        $relay = Relay::query()->create([
            'protocol' => Relay::PROTOCOL_MASTODON,
            'inbox_url' => 'https://relay-update.example/inbox',
            'inbox_url_hash' => hash('sha256', 'https://relay-update.example/inbox'),
            'state' => Relay::STATE_IDLE,
        ]);
        $this->actingAs($moderator)
            ->patch(route('admin.relays.update', $relay), ['publish_enabled' => '1'])
            ->assertForbidden();
    }
}
