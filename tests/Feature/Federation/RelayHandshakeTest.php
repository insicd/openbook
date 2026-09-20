<?php

namespace Tests\Feature\Federation;

use App\Application\Services\InstanceRelayActor;
use App\Application\Services\RelayHandshakeManager;
use App\Domain\Accounts\User;
use App\Domain\Federation\Relay;
use App\Federation\Actors\Actor;
use App\Federation\Inbox\InboxActivityProcessor;
use App\Federation\Inbox\InboxItem;
use App\Federation\Serialization\RelayActivitySerializer;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class RelayHandshakeTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_admin_can_queue_a_mastodon_relay_follow(): void
    {
        Queue::fake();
        $admin = $this->admin('relaysubscribe');
        $relay = $this->relay('relay.example');

        $this->actingAs($admin)
            ->post(route('admin.relays.subscribe', $relay))
            ->assertRedirect();

        $relay->refresh();
        $this->assertSame(Relay::STATE_PENDING, $relay->state);
        $this->assertMatchesRegularExpression(
            '#^'.preg_quote(url('/activities/relay-follows'), '#').'/[0-9a-f-]{36}$#',
            $relay->follow_activity_uri,
        );

        Queue::assertPushed(DeliverActivityJob::class, function (DeliverActivityJob $job) use ($relay): bool {
            return $job->relayId === $relay->id
                && $job->inboxUrl === 'https://relay.example/inbox'
                && $job->activity['type'] === 'Follow'
                && $job->activity['actor'] === url('/relay')
                && $job->activity['object'] === RelayActivitySerializer::PUBLIC_STREAM;
        });
    }

    public function test_retry_uses_a_fresh_follow_activity_id(): void
    {
        Queue::fake();
        $admin = $this->admin('relayretry');
        $relay = $this->relay('relay-retry.example');
        $manager = app(RelayHandshakeManager::class);

        $manager->subscribe($admin, $relay);
        $firstActivityUri = $relay->refresh()->follow_activity_uri;
        $relay->forceFill(['state' => Relay::STATE_FAILED])->save();
        $manager->subscribe($admin, $relay);

        $this->assertNotSame($firstActivityUri, $relay->refresh()->follow_activity_uri);
    }

    public function test_actor_relay_follow_targets_the_remote_actor(): void
    {
        Queue::fake();
        $admin = $this->admin('actorrelaysubscribe');
        $remote = $this->createRemoteActor('relay', 'events.example', [
            'type' => Actor::TYPE_APPLICATION,
        ]);
        $relay = Relay::query()->create([
            'protocol' => Relay::PROTOCOL_ACTOR,
            'actor_uri' => $remote->uri,
            'inbox_url' => $remote->endpoints->inbox,
            'inbox_url_hash' => hash('sha256', $remote->endpoints->inbox),
            'state' => Relay::STATE_IDLE,
            'receive_enabled' => true,
            'publish_enabled' => false,
        ]);

        app(RelayHandshakeManager::class)->subscribe($admin, $relay);

        Queue::assertPushed(DeliverActivityJob::class, function (DeliverActivityJob $job) use ($relay, $remote): bool {
            return $job->relayId === $relay->id
                && $job->inboxUrl === $remote->endpoints->inbox
                && $job->activity['type'] === 'Follow'
                && $job->activity['actor'] === url('/relay')
                && $job->activity['object'] === $remote->uri;
        });

        $serviceActor = app(InstanceRelayActor::class)->getOrCreate();
        $accept = $this->responseActivity('Accept', $relay->refresh(), $remote, $serviceActor);
        $accept['object']['object'] = $remote->uri;

        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($accept, $remote));
        $this->assertSame(Relay::STATE_ACCEPTED, $relay->refresh()->state);
    }

    public function test_authenticated_accept_and_reject_update_the_matching_relay(): void
    {
        Queue::fake();
        $admin = $this->admin('relayresponses');
        $acceptedRelay = $this->relay('relay.example');
        $rejectedRelay = $this->relay('reject.example');
        $manager = app(RelayHandshakeManager::class);
        $manager->subscribe($admin, $acceptedRelay);
        $manager->subscribe($admin, $rejectedRelay);

        $acceptedActor = $this->createRemoteActor('relay', 'relay.example');
        $rejectedActor = $this->createRemoteActor('relay', 'reject.example');
        $serviceActor = app(InstanceRelayActor::class)->getOrCreate();

        $accept = $this->responseActivity('Accept', $acceptedRelay->refresh(), $acceptedActor, $serviceActor);
        $reject = $this->responseActivity('Reject', $rejectedRelay->refresh(), $rejectedActor, $serviceActor);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($accept, $acceptedActor));
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($reject, $rejectedActor));

        $acceptedRelay->refresh();
        $rejectedRelay->refresh();
        $this->assertSame(Relay::STATE_ACCEPTED, $acceptedRelay->state);
        $this->assertSame($acceptedActor->uri, $acceptedRelay->actor_uri);
        $this->assertNotNull($acceptedRelay->accepted_at);
        $this->assertSame(Relay::STATE_REJECTED, $rejectedRelay->state);
        $this->assertNull($rejectedRelay->accepted_at);
    }

    public function test_accept_allows_a_relay_normalized_follow_object(): void
    {
        Queue::fake();
        $admin = $this->admin('relaynormalized');
        $relay = $this->relay('relay-normalized.example');
        $manager = app(RelayHandshakeManager::class);
        $manager->subscribe($admin, $relay);

        $remoteActor = $this->createRemoteActor('relay', 'relay-normalized.example');
        $serviceActor = app(InstanceRelayActor::class)->getOrCreate();
        $accept = $this->responseActivity('Accept', $relay->refresh(), $remoteActor, $serviceActor);
        $accept['object']['object'] = $remoteActor->uri;

        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($accept, $remoteActor));
        $this->assertSame(Relay::STATE_ACCEPTED, $relay->refresh()->state);
        $this->assertSame($remoteActor->uri, $relay->actor_uri);
    }

    public function test_response_from_an_unrelated_host_is_ignored(): void
    {
        Queue::fake();
        $admin = $this->admin('relayattacker');
        $relay = $this->relay('relay.example');
        app(RelayHandshakeManager::class)->subscribe($admin, $relay);

        $attacker = $this->createRemoteActor('relay', 'attacker.example');
        $serviceActor = app(InstanceRelayActor::class)->getOrCreate();
        $activity = $this->responseActivity('Accept', $relay->refresh(), $attacker, $serviceActor);

        $this->assertSame(InboxItem::STATUS_IGNORED, $this->process($activity, $attacker));
        $this->assertSame(Relay::STATE_PENDING, $relay->refresh()->state);
        $this->assertNull($relay->actor_uri);
    }

    public function test_unsubscribe_is_local_first_and_queues_an_undo(): void
    {
        Queue::fake();
        $admin = $this->admin('relayunsubscribe');
        $relay = $this->relay('relay.example');
        app(RelayHandshakeManager::class)->subscribe($admin, $relay);
        $relay->forceFill(['state' => Relay::STATE_ACCEPTED, 'accepted_at' => now()])->save();
        Queue::fake();

        $this->actingAs($admin)
            ->delete(route('admin.relays.unsubscribe', $relay))
            ->assertRedirect();

        $relay->refresh();
        $this->assertSame(Relay::STATE_IDLE, $relay->state);
        $this->assertNull($relay->follow_activity_uri);
        $this->assertNull($relay->accepted_at);

        Queue::assertPushed(DeliverActivityJob::class, function (DeliverActivityJob $job) use ($relay): bool {
            return $job->relayId === $relay->id
                && $job->activity['type'] === 'Undo'
                && ($job->activity['object']['type'] ?? null) === 'Follow'
                && ($job->activity['object']['object'] ?? null) === RelayActivitySerializer::PUBLIC_STREAM;
        });
    }

    private function admin(string $username): User
    {
        $admin = $this->createFullAccount($username);
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    private function relay(string $host): Relay
    {
        $url = 'https://'.$host.'/inbox';

        return Relay::query()->create([
            'protocol' => Relay::PROTOCOL_MASTODON,
            'inbox_url' => $url,
            'inbox_url_hash' => hash('sha256', $url),
            'state' => Relay::STATE_IDLE,
            'receive_enabled' => true,
            'publish_enabled' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function responseActivity(string $type, Relay $relay, Actor $remoteActor, Actor $serviceActor): array
    {
        return [
            'id' => $remoteActor->uri.'/activities/'.strtolower($type).'/'.$relay->id,
            'type' => $type,
            'actor' => $remoteActor->uri,
            'object' => [
                'id' => $relay->follow_activity_uri,
                'type' => 'Follow',
                'actor' => $serviceActor->activityPubId(),
                'object' => RelayActivitySerializer::PUBLIC_STREAM,
            ],
        ];
    }

    /** @param array<string, mixed> $activity */
    private function process(array $activity, Actor $signer): string
    {
        $item = InboxItem::query()->create([
            'is_shared' => true,
            'remote_activity_uri' => $activity['id'],
            'activity_type' => $activity['type'],
            'actor_uri' => $signer->uri,
            'payload' => json_encode($activity, JSON_THROW_ON_ERROR),
            'signature_valid' => true,
            'status' => InboxItem::STATUS_PENDING,
            'received_at' => now(),
        ]);

        return app(InboxActivityProcessor::class)->process($item);
    }
}
