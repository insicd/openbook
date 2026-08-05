<?php

namespace Tests\Feature\Federation;

use App\Domain\Posts\Mention;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
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
        $repliedTo = $this->createRemoteActor('citato');

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
}
