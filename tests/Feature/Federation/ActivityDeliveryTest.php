<?php

namespace Tests\Feature\Federation;

use App\Application\Services\InstanceRelayActor;
use App\Domain\Comments\Comment;
use App\Domain\Events\Event;
use App\Domain\Events\EventComment;
use App\Domain\Federation\Relay;
use App\Domain\Posts\Mention;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Delivery\ActivityDelivery;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

/**
 * Verifica il fan-out delle attivita' in uscita verso le inbox remote:
 * deduplicazione sulla "sharedInbox", esclusione dei follower locali e dei
 * follow non ancora accettati, e le regole specifiche per gli oggetti
 * diretti ("to": solo i destinatari, non tutti i follower).
 */
class ActivityDeliveryTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_it_delivers_to_each_unique_remote_inbox_only_once(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('autoreseguito');

        $followerSameServer1 = $this->createRemoteActor('uno', 'stessoserver.example');
        $followerSameServer2 = $this->createRemoteActor('due', 'stessoserver.example');
        $followerOtherServer = $this->createRemoteActor('tre', 'altroserver.example');

        foreach ([$followerSameServer1, $followerSameServer2, $followerOtherServer] as $follower) {
            Follow::query()->create([
                'follower_id' => $follower->id,
                'following_id' => $author->actor->id,
                'status' => Follow::STATUS_ACCEPTED,
                'requested_at' => now(),
                'accepted_at' => now(),
            ]);
        }

        app(ActivityDelivery::class)->deliverToFollowers($author->actor, ['type' => 'Create', 'id' => 'x']);

        Queue::assertPushed(DeliverActivityJob::class, 2);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->inboxUrl === 'https://stessoserver.example/inbox');
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->inboxUrl === $followerOtherServer->endpoints->shared_inbox);
    }

    public function test_it_does_not_deliver_to_local_followers_or_pending_requests(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('autoreconlocali');
        $localFollower = $this->createFullAccount('localseguace');
        $pendingRemote = $this->createRemoteActor('pendente');

        Follow::query()->create([
            'follower_id' => $localFollower->actor->id,
            'following_id' => $author->actor->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        Follow::query()->create([
            'follower_id' => $pendingRemote->id,
            'following_id' => $author->actor->id,
            'status' => Follow::STATUS_PENDING,
            'requested_at' => now(),
        ]);

        app(ActivityDelivery::class)->deliverToFollowers($author->actor, ['type' => 'Create', 'id' => 'x']);

        Queue::assertNotPushed(DeliverActivityJob::class);
    }

    public function test_deliver_to_skips_local_targets_and_targets_without_an_inbox(): void
    {
        Queue::fake();
        Http::fake([
            'https://remoto.example/users/senzainbox' => Http::response('gone', 404),
        ]);

        $author = $this->createFullAccount('mittente');
        $localTarget = $this->createFullAccount('destinatariolocale');
        $remoteWithoutEndpoints = $this->createRemoteActor('senzainbox');
        $remoteWithoutEndpoints->endpoints()->delete();

        $delivery = app(ActivityDelivery::class);
        $delivery->deliverTo($author->actor, $localTarget->actor, ['type' => 'Follow', 'id' => 'x']);
        $delivery->deliverTo($author->actor, $remoteWithoutEndpoints->fresh(), ['type' => 'Follow', 'id' => 'x']);

        Queue::assertNotPushed(DeliverActivityJob::class);
    }

    public function test_a_direct_post_is_delivered_only_to_mentioned_remote_actors_not_to_all_followers(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('autoremenzioni');
        $follower = $this->createRemoteActor('nonmenzionato');
        $mentioned = $this->createRemoteActor('menzionato');

        Follow::query()->create([
            'follower_id' => $follower->id,
            'following_id' => $author->actor->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        $post = Post::query()->create([
            'actor_id' => $author->actor->id,
            'body' => 'Messaggio privato per una persona sola.',
            'visibility' => Post::VISIBILITY_DIRECT,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        Mention::query()->create([
            'mentionable_type' => $post->getMorphClass(),
            'mentionable_id' => $post->id,
            'actor_id' => $mentioned->id,
        ]);

        $post->load('mentions.actor');

        app(ActivityDelivery::class)->deliverContent($post, ['type' => 'Create', 'id' => 'x']);

        Queue::assertPushed(DeliverActivityJob::class, 1);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->inboxUrl === ($mentioned->endpoints->shared_inbox ?: $mentioned->endpoints->inbox));
    }

    public function test_a_public_post_is_delivered_to_followers_and_to_an_extra_direct_target(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('autorepubblico');
        $follower = $this->createRemoteActor('seguacepubblico');
        $repliedTo = $this->createRemoteActor('citato', 'citato.example');

        Follow::query()->create([
            'follower_id' => $follower->id,
            'following_id' => $author->actor->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        $post = Post::query()->create([
            'actor_id' => $author->actor->id,
            'body' => 'Un post pubblico in risposta a qualcuno.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $post->load('mentions.actor');

        app(ActivityDelivery::class)->deliverContent($post, ['type' => 'Create', 'id' => 'x'], [$repliedTo]);

        Queue::assertPushed(DeliverActivityJob::class, 2);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->inboxUrl === $follower->endpoints->shared_inbox);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->inboxUrl === ($repliedTo->endpoints->shared_inbox ?: $repliedTo->endpoints->inbox));
    }

    public function test_a_public_event_is_delivered_to_followers_and_remote_mentions(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('eventowner');
        $follower = $this->createRemoteActor('eventfollower', 'followers.example');
        $mentioned = $this->createRemoteActor('eventguest', 'mentions.example');

        Follow::query()->create([
            'follower_id' => $follower->id,
            'following_id' => $author->actor->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        $event = Event::query()->create([
            'actor_id' => $author->actor->id,
            'uri' => url('/eventi/delivery-test'),
            'name' => 'Evento pubblico',
            'visibility' => Event::VISIBILITY_PUBLIC,
            'status' => Event::STATUS_SCHEDULED,
            'start_at' => now()->addDay(),
            'published_at' => now(),
        ]);
        Mention::query()->create([
            'mentionable_type' => $event->getMorphClass(),
            'mentionable_id' => $event->id,
            'actor_id' => $mentioned->id,
        ]);

        app(ActivityDelivery::class)->deliverContent($event, ['type' => 'Create', 'id' => 'event-create']);

        Queue::assertPushed(DeliverActivityJob::class, 2);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->inboxUrl === $follower->endpoints->shared_inbox);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->inboxUrl === $mentioned->endpoints->shared_inbox);
    }

    public function test_public_posts_and_events_are_delivered_to_publish_enabled_relays(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('relaypublisher');
        $relay = $this->relay('https://relay.example/inbox');
        $actorRelay = $this->relay('https://events.example/relay/inbox', [
            'protocol' => Relay::PROTOCOL_ACTOR,
        ]);
        $this->relay('https://receive-only.example/inbox', ['publish_enabled' => false]);
        $this->relay('https://pending.example/inbox', ['state' => Relay::STATE_PENDING]);
        $post = Post::query()->create([
            'actor_id' => $author->actor->id,
            'body' => 'Post pubblico per il relay.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $event = Event::query()->create([
            'actor_id' => $author->actor->id,
            'uri' => url('/eventi/relay-publish'),
            'name' => 'Evento pubblico per il relay',
            'visibility' => Event::VISIBILITY_PUBLIC,
            'status' => Event::STATUS_SCHEDULED,
            'start_at' => now()->addDay(),
            'published_at' => now(),
        ]);

        app(ActivityDelivery::class)->deliverContent($post, ['type' => 'Create', 'id' => 'post-create']);
        app(ActivityDelivery::class)->deliverContent($event, ['type' => 'Update', 'id' => 'event-update']);

        Queue::assertPushed(DeliverActivityJob::class, 2);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->relayId === $relay->id
            && $job->activity['id'] === 'post-create');
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->relayId === $relay->id
            && $job->activity['id'] === 'event-update');
        Queue::assertNotPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->relayId === $actorRelay->id);
    }

    public function test_comments_on_public_posts_and_events_are_delivered_to_relays(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('relaycommenter');
        $relay = $this->relay('https://relay.example/inbox');
        $post = Post::query()->create([
            'actor_id' => $author->actor->id,
            'body' => 'Post pubblico commentabile.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $comment = Comment::query()->create([
            'post_id' => $post->id,
            'actor_id' => $author->actor->id,
            'body' => 'Risposta pubblica.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);
        $event = Event::query()->create([
            'actor_id' => $author->actor->id,
            'uri' => url('/eventi/relay-comment'),
            'name' => 'Evento pubblico commentabile',
            'visibility' => Event::VISIBILITY_PUBLIC,
            'status' => Event::STATUS_SCHEDULED,
            'start_at' => now()->addDay(),
            'published_at' => now(),
        ]);
        $eventComment = EventComment::query()->create([
            'event_id' => $event->id,
            'actor_id' => $author->actor->id,
            'uri' => url('/commenti-eventi/relay-comment'),
            'body' => 'Risposta pubblica all evento.',
            'status' => EventComment::STATUS_PUBLISHED,
        ]);

        app(ActivityDelivery::class)->deliverContent($comment, ['type' => 'Create', 'id' => 'comment-create']);
        app(ActivityDelivery::class)->deliverContent($eventComment, ['type' => 'Delete', 'id' => 'event-comment-delete']);

        Queue::assertPushed(DeliverActivityJob::class, 2);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->relayId === $relay->id
            && $job->activity['id'] === 'comment-create');
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->relayId === $relay->id
            && $job->activity['id'] === 'event-comment-delete');
    }

    public function test_comments_on_non_public_content_are_not_delivered_to_relays(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('relayprivatecommenter');
        $this->relay('https://relay.example/inbox');
        $post = Post::query()->create([
            'actor_id' => $author->actor->id,
            'body' => 'Post non elencato commentabile.',
            'visibility' => Post::VISIBILITY_UNLISTED,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $comment = Comment::query()->create([
            'post_id' => $post->id,
            'actor_id' => $author->actor->id,
            'body' => 'Risposta non pubblica.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);
        $event = Event::query()->create([
            'actor_id' => $author->actor->id,
            'uri' => url('/eventi/relay-unlisted-comment'),
            'name' => 'Evento non elencato commentabile',
            'visibility' => Event::VISIBILITY_UNLISTED,
            'status' => Event::STATUS_SCHEDULED,
            'start_at' => now()->addDay(),
            'published_at' => now(),
        ]);
        $eventComment = EventComment::query()->create([
            'event_id' => $event->id,
            'actor_id' => $author->actor->id,
            'uri' => url('/commenti-eventi/relay-unlisted-comment'),
            'body' => 'Risposta non pubblica all evento.',
            'status' => EventComment::STATUS_PUBLISHED,
        ]);

        app(ActivityDelivery::class)->deliverContent($comment, ['type' => 'Create', 'id' => 'private-comment']);
        app(ActivityDelivery::class)->deliverContent($eventComment, ['type' => 'Create', 'id' => 'private-event-comment']);

        Queue::assertNotPushed(DeliverActivityJob::class);
    }

    public function test_non_public_remote_and_non_content_activities_are_not_published_to_relays(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('relayprivate');
        $remote = $this->createRemoteActor('relayauthor', 'remote.example');
        $this->relay('https://relay.example/inbox');
        $unlisted = Post::query()->create([
            'actor_id' => $author->actor->id,
            'body' => 'Post non elencato.',
            'visibility' => Post::VISIBILITY_UNLISTED,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $remotePost = Post::query()->create([
            'actor_id' => $remote->id,
            'uri' => $remote->uri.'/statuses/1',
            'body' => 'Post remoto.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        app(ActivityDelivery::class)->deliverContent($unlisted, ['type' => 'Create', 'id' => 'unlisted']);
        app(ActivityDelivery::class)->deliverContent($remotePost, ['type' => 'Create', 'id' => 'remote']);
        $unlisted->update(['visibility' => Post::VISIBILITY_PUBLIC]);
        app(ActivityDelivery::class)->deliverContent($unlisted->fresh(), ['type' => 'Like', 'id' => 'like']);

        Queue::assertNotPushed(DeliverActivityJob::class);
    }

    public function test_relay_delivery_is_deduplicated_against_an_existing_follower_inbox(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('relaydedupe');
        $follower = $this->createRemoteActor('relaypeer', 'relay.example');
        $relay = $this->relay($follower->endpoints->shared_inbox);
        Follow::query()->create([
            'follower_id' => $follower->id,
            'following_id' => $author->actor->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);
        $post = Post::query()->create([
            'actor_id' => $author->actor->id,
            'body' => 'Una sola consegna.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        app(ActivityDelivery::class)->deliverContent($post, ['type' => 'Create', 'id' => 'dedupe']);

        Queue::assertPushed(DeliverActivityJob::class, 1);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->inboxUrl === $relay->inbox_url);
    }

    public function test_an_explicit_delete_withdraws_content_that_is_no_longer_public(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('relaywithdraw');
        $relay = $this->relay('https://relay.example/inbox');
        $post = Post::query()->create([
            'actor_id' => $author->actor->id,
            'body' => 'Ora non elencato.',
            'visibility' => Post::VISIBILITY_UNLISTED,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        app(ActivityDelivery::class)->deliverContent(
            $post,
            ['type' => 'Update', 'id' => 'normal-update'],
            relayActivity: ['type' => 'Delete', 'id' => 'relay-delete'],
        );

        Queue::assertPushed(DeliverActivityJob::class, 1);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->relayId === $relay->id
            && $job->activity['type'] === 'Delete');
    }

    public function test_public_content_is_announced_to_application_followers_of_the_relay_actor(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('actorsource');
        $serviceActor = app(InstanceRelayActor::class)->getOrCreate();
        $remoteRelay = $this->createRemoteActor('events', 'actor-relay.example', [
            'type' => Actor::TYPE_APPLICATION,
        ]);
        Follow::query()->create([
            'follower_id' => $remoteRelay->id,
            'following_id' => $serviceActor->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);
        $post = Post::query()->create([
            'actor_id' => $author->actor->id,
            'body' => 'Contenuto pubblico per Actor relay.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $objectUri = url('/posts/'.$post->id);

        app(ActivityDelivery::class)->deliverContent($post, [
            'type' => 'Create',
            'id' => $objectUri.'/attivita',
            'object' => ['id' => $objectUri],
        ]);

        Queue::assertPushed(DeliverActivityJob::class, 1);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->inboxUrl === $remoteRelay->endpoints->shared_inbox
            && $job->signingActorId === $serviceActor->id
            && $job->activity['type'] === 'Announce'
            && $job->activity['actor'] === url('/relay')
            && $job->activity['object'] === $objectUri
            && ($job->activity['to'][0] ?? null) === url('/relay/followers'));
    }

    public function test_updates_have_a_distinct_actor_relay_announce_and_deletes_keep_the_original_author(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('actorupdates');
        $serviceActor = app(InstanceRelayActor::class)->getOrCreate();
        $remoteRelay = $this->createRemoteActor('events', 'updates-relay.example', [
            'type' => Actor::TYPE_APPLICATION,
        ]);
        Follow::query()->create([
            'follower_id' => $remoteRelay->id,
            'following_id' => $serviceActor->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);
        $post = Post::query()->create([
            'actor_id' => $author->actor->id,
            'body' => 'Contenuto aggiornato.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $objectUri = url('/posts/'.$post->id);

        app(ActivityDelivery::class)->deliverContent($post, [
            'type' => 'Update',
            'id' => $objectUri.'/attivita/update/1',
            'object' => ['id' => $objectUri],
        ]);
        app(ActivityDelivery::class)->deliverContent($post, [
            'type' => 'Delete',
            'id' => $objectUri.'/attivita/delete',
            'object' => $objectUri,
        ]);

        Queue::assertPushed(DeliverActivityJob::class, 2);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Announce'
            && $job->activity['id'] !== url('/relay/activities/announces/'.hash('sha256', $objectUri))
            && $job->signingActorId === $serviceActor->id);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Delete'
            && $job->signingActorId === $author->actor->id);
    }

    public function test_unlisted_content_and_disabled_actor_relays_are_not_published(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('actordisabled');
        $serviceActor = app(InstanceRelayActor::class)->getOrCreate();
        $remoteRelay = $this->createRemoteActor('events', 'disabled-relay.example', [
            'type' => Actor::TYPE_APPLICATION,
        ]);
        Follow::query()->create([
            'follower_id' => $remoteRelay->id,
            'following_id' => $serviceActor->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);
        $this->relay($remoteRelay->endpoints->shared_inbox, [
            'protocol' => Relay::PROTOCOL_ACTOR,
            'actor_uri' => $remoteRelay->uri,
            'publish_enabled' => false,
        ]);
        $post = Post::query()->create([
            'actor_id' => $author->actor->id,
            'body' => 'Contenuto pubblico disabilitato.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        app(ActivityDelivery::class)->deliverContent($post, [
            'type' => 'Create',
            'id' => 'https://openbook.test/activities/disabled',
            'object' => ['id' => url('/posts/'.$post->id)],
        ]);
        $post->update(['visibility' => Post::VISIBILITY_UNLISTED]);
        app(ActivityDelivery::class)->deliverContent($post->fresh(), [
            'type' => 'Create',
            'id' => 'https://openbook.test/activities/unlisted',
            'object' => ['id' => url('/posts/'.$post->id)],
        ]);

        Queue::assertNothingPushed();
    }

    public function test_actor_relay_delivery_is_deduplicated_against_a_normal_follower_inbox(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('actordedupe');
        $serviceActor = app(InstanceRelayActor::class)->getOrCreate();
        $remoteRelay = $this->createRemoteActor('events', 'same-inbox.example', [
            'type' => Actor::TYPE_APPLICATION,
        ]);

        foreach ([$author->actor, $serviceActor] as $target) {
            Follow::query()->create([
                'follower_id' => $remoteRelay->id,
                'following_id' => $target->id,
                'status' => Follow::STATUS_ACCEPTED,
                'requested_at' => now(),
                'accepted_at' => now(),
            ]);
        }
        $post = Post::query()->create([
            'actor_id' => $author->actor->id,
            'body' => 'Una consegna per la stessa inbox.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        app(ActivityDelivery::class)->deliverContent($post, [
            'type' => 'Create',
            'id' => 'https://openbook.test/activities/deduplicated-actor-relay',
            'object' => ['id' => url('/posts/'.$post->id)],
        ]);

        Queue::assertPushed(DeliverActivityJob::class, 1);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Create'
            && $job->signingActorId === $author->actor->id);
    }

    /** @param array<string, mixed> $overrides */
    private function relay(string $inboxUrl, array $overrides = []): Relay
    {
        $host = parse_url($inboxUrl, PHP_URL_HOST);

        return Relay::query()->create(array_merge([
            'protocol' => Relay::PROTOCOL_MASTODON,
            'actor_uri' => 'https://'.$host.'/actor',
            'inbox_url' => $inboxUrl,
            'inbox_url_hash' => hash('sha256', $inboxUrl),
            'state' => Relay::STATE_ACCEPTED,
            'receive_enabled' => true,
            'publish_enabled' => true,
            'accepted_at' => now(),
        ], $overrides));
    }
}
