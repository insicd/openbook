<?php

namespace Tests\Feature\Federation;

use App\Application\Services\CommunityRegistrar;
use App\Application\Services\FollowManager;
use App\Domain\Comments\Comment;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use App\Domain\Reactions\Like;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Inbox\InboxActivityProcessor;
use App\Federation\Inbox\InboxItem;
use App\Federation\Serialization\ActivitySerializer;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

/**
 * Verifica la trasformazione delle attivita' in ingresso (gia' autenticate)
 * negli effetti di dominio corrispondenti. A differenza di
 * {@see InboxSignatureTest} (livello di
 * trasporto), qui gli InboxItem sono creati direttamente cosi' da isolare
 * la sola logica di {@see InboxActivityProcessor}. Nessuna richiesta HTTP
 * reale: gli Actor remoti sono gia' "in cache" locale e le consegne in
 * uscita sono intercettate con Queue::fake().
 */
class InboxActivityProcessorTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $activity
     */
    private function process(array $activity, Actor $signer): string
    {
        $item = InboxItem::query()->create([
            'is_shared' => false,
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

    public function test_a_follow_to_an_open_local_account_is_accepted_and_dispatches_an_accept(): void
    {
        Queue::fake();
        $target = $this->createFullAccount('aperto');
        $remote = $this->createRemoteActor('carol');

        $activity = [
            'id' => 'https://remoto.example/activities/'.uniqid(),
            'type' => 'Follow',
            'actor' => $remote->uri,
            'object' => $target->actor->uri,
        ];

        $status = $this->process($activity, $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertDatabaseHas('follows', [
            'follower_id' => $remote->id,
            'following_id' => $target->actor->id,
            'status' => Follow::STATUS_ACCEPTED,
            'remote_activity_uri' => $activity['id'],
        ]);

        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->inboxUrl === ($remote->endpoints->shared_inbox ?: $remote->endpoints->inbox)
            && $job->activity['type'] === 'Accept'
            && $job->signingActorId === $target->actor->id
            && ($job->activity['to'][0] ?? null) === $remote->uri);
    }

    public function test_a_follow_to_a_protected_local_account_stays_pending_without_sending_an_accept(): void
    {
        Queue::fake();
        $target = $this->createFullAccount('protetto');
        $target->actor->update(['manually_approves_followers' => true]);
        $remote = $this->createRemoteActor('dave');

        $activity = [
            'id' => 'https://remoto.example/activities/'.uniqid(),
            'type' => 'Follow',
            'actor' => $remote->uri,
            'object' => $target->actor->uri,
        ];

        $status = $this->process($activity, $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertDatabaseHas('follows', [
            'follower_id' => $remote->id,
            'following_id' => $target->actor->id,
            'status' => Follow::STATUS_PENDING,
        ]);

        Queue::assertNotPushed(DeliverActivityJob::class);
    }

    public function test_a_remote_follow_to_a_public_local_community_is_accepted_and_increments_members(): void
    {
        Queue::fake();
        $owner = $this->createFullAccount('groupowner');
        $community = app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'aperta',
            'name' => 'Community aperta',
        ]);
        $remote = $this->createRemoteActor('joinremote');

        $activity = [
            'id' => 'https://remoto.example/activities/'.uniqid(),
            'type' => 'Follow',
            'actor' => $remote->uri,
            'object' => $community->actor->uri,
            'to' => [$community->actor->uri],
        ];

        $status = $this->process($activity, $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertDatabaseHas('follows', [
            'follower_id' => $remote->id,
            'following_id' => $community->actor_id,
            'status' => Follow::STATUS_ACCEPTED,
        ]);
        $this->assertSame(2, $community->fresh()->members_count);

        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Accept'
            && $job->signingActorId === $community->actor_id
            && ($job->activity['to'][0] ?? null) === $remote->uri
            && ($job->activity['object']['to'][0] ?? null) === $community->actor->uri);
    }

    public function test_a_remote_follow_using_community_profile_url_is_resolved_and_accepted(): void
    {
        Queue::fake();
        config(['openbook.domain' => 'openbook.test']);
        $owner = $this->createFullAccount('slugowner');
        $community = app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'profilourl',
            'name' => 'Via profilo',
        ]);
        $remote = $this->createRemoteActor('viaurl');

        $activity = [
            'id' => 'https://remoto.example/activities/'.uniqid(),
            'type' => 'Follow',
            'actor' => $remote->uri,
            'object' => url('/c/profilourl'),
        ];

        $status = $this->process($activity, $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertDatabaseHas('follows', [
            'follower_id' => $remote->id,
            'following_id' => $community->actor_id,
            'status' => Follow::STATUS_ACCEPTED,
        ]);
        $this->assertSame(2, $community->fresh()->members_count);
    }

    public function test_a_remote_follow_to_a_private_local_community_stays_pending(): void
    {
        Queue::fake();
        $owner = $this->createFullAccount('privowner');
        $community = app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'privata',
            'name' => 'Community privata',
            'is_private' => true,
        ]);
        $remote = $this->createRemoteActor('wantin');

        $activity = [
            'id' => 'https://remoto.example/activities/'.uniqid(),
            'type' => 'Follow',
            'actor' => $remote->uri,
            'object' => $community->actor->uri,
        ];

        $status = $this->process($activity, $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertDatabaseHas('follows', [
            'follower_id' => $remote->id,
            'following_id' => $community->actor_id,
            'status' => Follow::STATUS_PENDING,
        ]);
        $this->assertSame(1, $community->fresh()->members_count);
        Queue::assertNotPushed(DeliverActivityJob::class);
    }

    public function test_an_accept_from_remote_completes_an_outgoing_follow(): void
    {
        Queue::fake();
        $localUser = $this->createFullAccount('richiedente');
        $remote = $this->createRemoteActor('erin');

        $follow = app(FollowManager::class)->follow($localUser->actor, $remote);
        $this->assertSame(Follow::STATUS_PENDING, $follow->status);

        $activity = [
            'id' => 'https://remoto.example/activities/'.uniqid(),
            'type' => 'Accept',
            'actor' => $remote->uri,
            'object' => ActivitySerializer::follow($follow),
        ];

        $status = $this->process($activity, $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertSame(Follow::STATUS_ACCEPTED, $follow->fresh()->status);
    }

    public function test_a_lemmy_style_accept_with_percent_encoded_actor_uri_is_recognized(): void
    {
        Queue::fake();
        $localUser = $this->createFullAccount('lemmyjoin');
        $group = $this->createRemoteActor('asklemmy', 'lemmy.example', [
            'type' => Actor::TYPE_GROUP,
        ]);

        $follow = app(FollowManager::class)->follow($localUser->actor, $group);
        $this->assertSame(Follow::STATUS_PENDING, $follow->status);

        // Alias legacy "/@user" (e eventuale percent-encoding) devono comunque
        // risolvere il follower locale dopo il passaggio a "/users/user".
        $legacyActor = str_replace('/users/', '/@', $localUser->actor->uri);

        $activity = [
            'id' => $group->uri.'/activities/accept/'.uniqid(),
            'type' => 'Accept',
            'actor' => $group->uri,
            'to' => [$localUser->actor->uri],
            'object' => [
                'id' => ActivitySerializer::followActivityUri($follow),
                'type' => 'Follow',
                'actor' => $legacyActor,
                'object' => $group->uri,
                'to' => [$group->uri],
            ],
        ];

        $status = $this->process($activity, $group);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertSame(Follow::STATUS_ACCEPTED, $follow->fresh()->status);
    }

    public function test_accept_with_only_follow_activity_id_completes_the_pending_follow(): void
    {
        Queue::fake();
        $localUser = $this->createFullAccount('idonly');
        $group = $this->createRemoteActor('forum', 'groups.example', [
            'type' => Actor::TYPE_GROUP,
        ]);

        $follow = app(FollowManager::class)->follow($localUser->actor, $group);

        $activity = [
            'id' => $group->uri.'/activities/accept/'.uniqid(),
            'type' => 'Accept',
            'actor' => $group->uri,
            'object' => ActivitySerializer::followActivityUri($follow),
        ];

        $status = $this->process($activity, $group);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertSame(Follow::STATUS_ACCEPTED, $follow->fresh()->status);
    }

    public function test_a_reject_from_remote_removes_the_pending_outgoing_follow(): void
    {
        Queue::fake();
        $localUser = $this->createFullAccount('respinto');
        $remote = $this->createRemoteActor('frank');

        $follow = app(FollowManager::class)->follow($localUser->actor, $remote);

        $activity = [
            'id' => 'https://remoto.example/activities/'.uniqid(),
            'type' => 'Reject',
            'actor' => $remote->uri,
            'object' => ActivitySerializer::follow($follow),
        ];

        $status = $this->process($activity, $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertDatabaseCount('follows', 0);
        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $localUser->id,
            'type' => Notification::TYPE_FOLLOW_REJECTED,
            'actor_id' => $remote->id,
        ]);
    }

    public function test_an_undo_follow_removes_the_incoming_relationship(): void
    {
        Queue::fake();
        $target = $this->createFullAccount('smesso');
        $remote = $this->createRemoteActor('grace');

        app(FollowManager::class)->follow($remote, $target->actor);
        $this->assertDatabaseCount('follows', 1);

        $activity = [
            'id' => 'https://remoto.example/activities/'.uniqid(),
            'type' => 'Undo',
            'actor' => $remote->uri,
            'object' => [
                'type' => 'Follow',
                'actor' => $remote->uri,
                'object' => $target->actor->uri,
            ],
        ];

        $status = $this->process($activity, $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertDatabaseCount('follows', 0);
    }

    public function test_a_like_from_remote_increments_the_local_post_counter_and_notifies_the_author(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('autoreamato');
        $remote = $this->createRemoteActor('heidi');

        $post = Post::query()->create([
            'actor_id' => $author->actor->id,
            'body' => 'Un post pubblico.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $activity = [
            'id' => 'https://remoto.example/activities/'.uniqid(),
            'type' => 'Like',
            'actor' => $remote->uri,
            'object' => url("/posts/{$post->id}"),
        ];

        $status = $this->process($activity, $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertSame(1, $post->fresh()->likes_count);
        $this->assertDatabaseHas('likes', [
            'actor_id' => $remote->id,
            'likeable_type' => (new Post)->getMorphClass(),
            'likeable_id' => $post->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $author->id,
            'type' => Notification::TYPE_LIKE,
        ]);
    }

    public function test_an_undo_like_decrements_the_local_post_counter(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('autoredisamato');
        $remote = $this->createRemoteActor('ivan');

        $post = Post::query()->create([
            'actor_id' => $author->actor->id,
            'body' => 'Un altro post pubblico.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $like = Like::query()->create([
            'actor_id' => $remote->id,
            'likeable_type' => $post->getMorphClass(),
            'likeable_id' => $post->id,
        ]);
        $post->increment('likes_count');

        $activity = [
            'id' => 'https://remoto.example/activities/'.uniqid(),
            'type' => 'Undo',
            'actor' => $remote->uri,
            'object' => [
                'type' => 'Like',
                'actor' => $remote->uri,
                'object' => url("/posts/{$post->id}"),
            ],
        ];

        $status = $this->process($activity, $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertSame(0, $post->fresh()->likes_count);
        $this->assertDatabaseMissing('likes', ['id' => $like->id]);
    }

    public function test_an_announce_from_remote_increments_the_local_post_counter(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('autorecondiviso');
        $remote = $this->createRemoteActor('judy');

        $post = Post::query()->create([
            'actor_id' => $author->actor->id,
            'body' => 'Post da condividere.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $activity = [
            'id' => 'https://remoto.example/activities/'.uniqid(),
            'type' => 'Announce',
            'actor' => $remote->uri,
            'object' => url("/posts/{$post->id}"),
        ];

        $status = $this->process($activity, $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertSame(1, $post->fresh()->announces_count);
        $this->assertDatabaseHas('announces', [
            'actor_id' => $remote->id,
            'post_id' => $post->id,
        ]);
    }

    public function test_a_remote_group_announce_of_an_embedded_note_is_cached_for_local_members(): void
    {
        Queue::fake();
        $member = $this->createFullAccount('membrogruppo');
        $group = $this->createRemoteActor('circolo', 'forum.example', [
            'type' => Actor::TYPE_GROUP,
            'name' => 'Circolo remoto',
        ]);
        $author = $this->createRemoteActor('scrittore', 'forum.example');

        $follow = app(FollowManager::class)->follow($member->actor, $group);
        $follow->update(['status' => Follow::STATUS_ACCEPTED, 'accepted_at' => now()]);

        $noteUri = $author->uri.'/posts/'.uniqid();
        $activity = [
            'id' => $group->uri.'/activities/'.uniqid(),
            'type' => 'Announce',
            'actor' => $group->uri,
            'object' => [
                'id' => $noteUri,
                'type' => 'Note',
                'attributedTo' => $author->uri,
                'content' => '<p>Post ritrasmesso dal gruppo</p>',
                'published' => now()->toAtomString(),
                'to' => ['https://www.w3.org/ns/activitystreams#Public', $group->uri],
            ],
        ];

        $status = $this->process($activity, $group);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertDatabaseHas('posts', [
            'uri' => $noteUri,
            'actor_id' => $author->id,
            'body' => 'Post ritrasmesso dal gruppo',
        ]);
        $post = Post::query()->where('uri', $noteUri)->first();
        $this->assertNotNull($post);
        $this->assertDatabaseHas('announces', [
            'actor_id' => $group->id,
            'post_id' => $post->id,
        ]);
    }

    public function test_a_followed_person_announce_of_a_remote_note_is_cached_and_shared(): void
    {
        Queue::fake();
        $follower = $this->createFullAccount('seguacebot');
        $bot = $this->createRemoteActor('hashtagbot', 'tags.example');
        $author = $this->createRemoteActor('autoreoriginale', 'mastodon.example');

        $follow = app(FollowManager::class)->follow($follower->actor, $bot);
        $follow->update(['status' => Follow::STATUS_ACCEPTED, 'accepted_at' => now()]);

        $noteUri = $author->uri.'/statuses/'.uniqid();
        $activity = [
            'id' => $bot->uri.'/activities/'.uniqid(),
            'type' => 'Announce',
            'actor' => $bot->uri,
            'published' => now()->subMinute()->toAtomString(),
            'object' => [
                'id' => $noteUri,
                'type' => 'Note',
                'attributedTo' => $author->uri,
                'content' => '<p>Nota boostata dal bot</p>',
                'published' => now()->subHour()->toAtomString(),
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ],
        ];

        $status = $this->process($activity, $bot);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertDatabaseHas('posts', [
            'uri' => $noteUri,
            'actor_id' => $author->id,
            'body' => 'Nota boostata dal bot',
        ]);
        $post = Post::query()->where('uri', $noteUri)->first();
        $this->assertNotNull($post);
        $this->assertDatabaseHas('announces', [
            'actor_id' => $bot->id,
            'post_id' => $post->id,
        ]);
    }

    public function test_a_person_announce_of_a_remote_note_uri_is_fetched_when_followed(): void
    {
        Queue::fake();
        $follower = $this->createFullAccount('seguacefetch');
        $bot = $this->createRemoteActor('relaybot', 'bot.example');

        $follow = app(FollowManager::class)->follow($follower->actor, $bot);
        $follow->update(['status' => Follow::STATUS_ACCEPTED, 'accepted_at' => now()]);

        // Come tags.pub: object = solo URI; autore remoto non ancora in cache.
        $authorUri = 'https://origine.example/users/remoto';
        $noteUri = $authorUri.'/posts/solo-id';
        $pem = "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAu\n-----END PUBLIC KEY-----";

        Http::fake([
            $noteUri => Http::response([
                'id' => $noteUri,
                'type' => 'Note',
                'attributedTo' => $authorUri,
                'content' => '<p>Fetchata dall URI</p>',
                'published' => now()->subHour()->toAtomString(),
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ], 200, ['Content-Type' => 'application/activity+json']),
            $authorUri => Http::response([
                'id' => $authorUri,
                'type' => 'Person',
                'preferredUsername' => 'remoto',
                'inbox' => $authorUri.'/inbox',
                'outbox' => $authorUri.'/outbox',
                'followers' => $authorUri.'/followers',
                'following' => $authorUri.'/following',
                'publicKey' => [
                    'id' => $authorUri.'#main-key',
                    'owner' => $authorUri,
                    'publicKeyPem' => $pem,
                ],
            ], 200, ['Content-Type' => 'application/activity+json']),
        ]);

        $activity = [
            'id' => $bot->uri.'/activities/'.uniqid(),
            'type' => 'Announce',
            'actor' => $bot->uri,
            'object' => $noteUri,
        ];

        $status = $this->process($activity, $bot);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertDatabaseHas('actors', [
            'uri' => $authorUri,
            'is_local' => false,
        ]);
        $this->assertDatabaseHas('posts', [
            'uri' => $noteUri,
            'body' => 'Fetchata dall URI',
        ]);
        $post = Post::query()->where('uri', $noteUri)->first();
        $this->assertNotNull($post);
        $this->assertDatabaseHas('announces', [
            'actor_id' => $bot->id,
            'post_id' => $post->id,
        ]);
    }

    public function test_a_person_announce_of_a_remote_note_is_ignored_without_local_followers(): void
    {
        Queue::fake();
        $bot = $this->createRemoteActor('botorfano', 'tags.example');
        $author = $this->createRemoteActor('sconosciuto', 'mastodon.example');

        $noteUri = $author->uri.'/statuses/'.uniqid();
        $activity = [
            'id' => $bot->uri.'/activities/'.uniqid(),
            'type' => 'Announce',
            'actor' => $bot->uri,
            'object' => [
                'id' => $noteUri,
                'type' => 'Note',
                'attributedTo' => $author->uri,
                'content' => '<p>Non rilevante</p>',
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ],
        ];

        $status = $this->process($activity, $bot);

        $this->assertSame(InboxItem::STATUS_IGNORED, $status);
        $this->assertDatabaseMissing('posts', ['uri' => $noteUri]);
        $this->assertDatabaseCount('announces', 0);
    }

    public function test_a_remote_group_announce_of_a_lemmy_style_page_is_cached(): void
    {
        Queue::fake();
        $member = $this->createFullAccount('membolemmy');
        $group = $this->createRemoteActor('asklemmy', 'lemmy.example', [
            'type' => Actor::TYPE_GROUP,
            'name' => 'Ask Lemmy',
        ]);
        $author = $this->createRemoteActor('nutomic', 'lemmy.example');

        $follow = app(FollowManager::class)->follow($member->actor, $group);
        $follow->update(['status' => Follow::STATUS_ACCEPTED, 'accepted_at' => now()]);

        $pageUri = 'https://lemmy.example/post/'.uniqid();
        $activity = [
            'id' => $group->uri.'/activities/announce/'.uniqid(),
            'type' => 'Announce',
            'actor' => $group->uri,
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'cc' => [$group->uri.'/followers'],
            'object' => [
                'id' => $group->uri.'/activities/create/'.uniqid(),
                'type' => 'Create',
                'actor' => $author->uri,
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                'cc' => [$group->uri],
                'object' => [
                    'id' => $pageUri,
                    'type' => 'Page',
                    'attributedTo' => $author->uri,
                    'name' => 'Titolo del post Lemmy',
                    'content' => '<p>Corpo del post Lemmy</p>',
                    'mediaType' => 'text/html',
                    'published' => now()->toAtomString(),
                    'to' => [$group->uri, 'https://www.w3.org/ns/activitystreams#Public'],
                ],
            ],
        ];

        $status = $this->process($activity, $group);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertDatabaseHas('posts', [
            'uri' => $pageUri,
            'actor_id' => $author->id,
            'title' => 'Titolo del post Lemmy',
            'body' => 'Corpo del post Lemmy',
        ]);
        $post = Post::query()->where('uri', $pageUri)->first();
        $this->assertNotNull($post);
        $this->assertDatabaseHas('announces', [
            'actor_id' => $group->id,
            'post_id' => $post->id,
        ]);
    }

    public function test_a_create_note_from_a_followed_remote_author_is_stored_as_a_local_post(): void
    {
        Queue::fake();
        $follower = $this->createFullAccount('follower');
        $remote = $this->createRemoteActor('kevin');
        $followToRemote = app(FollowManager::class)->follow($follower->actor, $remote);
        // Simula un Accept gia' ricevuto dal server remoto: senza questo
        // passaggio il follow resterebbe "pending" (vedi FollowManager::follow)
        // e la Note non risulterebbe rilevante per questa istanza.
        $followToRemote->update(['status' => Follow::STATUS_ACCEPTED, 'accepted_at' => now()]);

        $noteUri = $remote->uri.'/posts/'.uniqid();
        $activity = [
            'id' => $noteUri.'/attivita',
            'type' => 'Create',
            'actor' => $remote->uri,
            'object' => [
                'id' => $noteUri,
                'type' => 'Note',
                'attributedTo' => $remote->uri,
                'content' => '<p>Ciao dal fediverso!</p>',
                'published' => now()->toAtomString(),
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ],
        ];

        $status = $this->process($activity, $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertDatabaseHas('posts', [
            'uri' => $noteUri,
            'actor_id' => $remote->id,
            'body' => 'Ciao dal fediverso!',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
    }

    public function test_a_remote_quote_post_with_quote_url_embeds_the_cited_post(): void
    {
        Queue::fake();
        $follower = $this->createFullAccount('quotefollower');
        $quoter = $this->createRemoteActor('citante', 'masto.example');
        $originalAuthor = $this->createRemoteActor('originale', 'altra.example');

        $follow = app(FollowManager::class)->follow($follower->actor, $quoter);
        $follow->update(['status' => Follow::STATUS_ACCEPTED, 'accepted_at' => now()]);

        $quotedUri = $originalAuthor->uri.'/statuses/quoted-1';
        $quoteUri = $quoter->uri.'/statuses/quote-1';

        Http::fake([
            $quotedUri => Http::response([
                'id' => $quotedUri,
                'type' => 'Note',
                'attributedTo' => $originalAuthor->uri,
                'content' => '<p>Post originale citato</p>',
                'published' => now()->subHour()->toAtomString(),
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ], 200, ['Content-Type' => 'application/activity+json']),
        ]);

        $activity = [
            'id' => $quoteUri.'/activity',
            'type' => 'Create',
            'actor' => $quoter->uri,
            'object' => [
                'id' => $quoteUri,
                'type' => 'Note',
                'attributedTo' => $quoter->uri,
                'content' => '<p>La mia citazione</p><p>RE: '.$quotedUri.'</p>',
                'quoteUrl' => $quotedUri,
                'published' => now()->toAtomString(),
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ],
        ];

        $status = $this->process($activity, $quoter);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);

        $quoted = Post::query()->where('uri', $quotedUri)->first();
        $quote = Post::query()->where('uri', $quoteUri)->first();

        $this->assertNotNull($quoted);
        $this->assertNotNull($quote);
        $this->assertSame($quoted->id, $quote->quoted_post_id);
        $this->assertSame('La mia citazione', $quote->body);
        $this->assertSame('Post originale citato', $quoted->body);

        $html = $this->actingAs($follower)->get(route('posts.show', $quote));
        $html->assertOk();
        $html->assertSee('class="ob-post__quote"', false);
        $html->assertSee('Post originale citato', false);
        $html->assertDontSee('RE: '.$quotedUri, false);
    }

    public function test_a_remote_note_with_timezone_offset_is_stored_in_app_timezone(): void
    {
        Queue::fake();
        config(['app.timezone' => 'UTC']);

        $follower = $this->createFullAccount('tzfollower');
        $remote = $this->createRemoteActor('news', 'ziobudda.example');
        $follow = app(FollowManager::class)->follow($follower->actor, $remote);
        $follow->update(['status' => Follow::STATUS_ACCEPTED, 'accepted_at' => now()]);

        $noteUri = $remote->uri.'/posts/1737';
        $activity = [
            'id' => $noteUri.'/attivita',
            'type' => 'Create',
            'actor' => $remote->uri,
            'object' => [
                'id' => $noteUri,
                'type' => 'Note',
                'attributedTo' => $remote->uri,
                'content' => '<p>News con offset +02:00</p>',
                // Come ziobudda.org: senza conversione Eloquent salverebbe
                // 14:30 come UTC e in feed comparirebbe "tra 2 ore".
                'published' => '2026-08-04T14:30:00+02:00',
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ],
        ];

        $status = $this->process($activity, $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);

        $post = Post::query()->where('uri', $noteUri)->first();
        $this->assertNotNull($post);
        $this->assertSame('2026-08-04 12:30:00', $post->published_at?->format('Y-m-d H:i:s'));
    }

    public function test_a_wordpress_style_article_create_is_stored(): void
    {
        Queue::fake();
        $follower = $this->createFullAccount('wpfollower');
        $remote = $this->createRemoteActor('blogger', 'wp.example');
        app(FollowManager::class)->follow($follower->actor, $remote)
            ->update(['status' => Follow::STATUS_ACCEPTED, 'accepted_at' => now()]);

        $articleUri = $remote->uri.'/articles/'.uniqid();
        $status = $this->process([
            'id' => $articleUri.'/activity',
            'type' => 'Create',
            'actor' => $remote->uri,
            'object' => [
                'id' => $articleUri,
                'type' => 'Article',
                'attributedTo' => $remote->uri,
                'name' => 'Articolo WordPress',
                'content' => '<p>Testo con <a href="https://fonte.example">fonte</a>.</p>',
                'attachment' => [
                    [
                        'type' => 'Image',
                        'mediaType' => 'image/jpeg',
                        'url' => 'https://wp.example/wp-content/uploads/cover.jpg',
                        'name' => 'Copertina',
                    ],
                ],
                'published' => now()->toAtomString(),
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ],
        ], $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $post = Post::query()->where('uri', $articleUri)->first();
        $this->assertNotNull($post);
        $this->assertSame('Articolo WordPress', $post->title);
        $this->assertStringContainsString('fonte', $post->body);
        $this->assertStringContainsString('https://fonte.example', $post->body);
        $this->assertCount(1, $post->media);
        $this->assertSame('https://wp.example/wp-content/uploads/cover.jpg', $post->media->first()->url());
    }

    public function test_a_wordpress_style_note_with_preview_and_full_image_attachments_keeps_one(): void
    {
        Queue::fake();
        $follower = $this->createFullAccount('wpimgfollower');
        $remote = $this->createRemoteActor('blogger', 'spcnet.it');
        app(FollowManager::class)->follow($follower->actor, $remote)
            ->update(['status' => Follow::STATUS_ACCEPTED, 'accepted_at' => now()]);

        $noteUri = $remote->uri.'/posts/'.uniqid();
        $full = 'https://spcnet.it/wp-content/uploads/2026/08/claude-code-cross-session-messaging-1024x572.jpg';
        $thumb = 'https://spcnet.it/wp-content/uploads/2026/08/claude-code-cross-session-messaging-150x150.jpg';

        $status = $this->process([
            'id' => $noteUri.'/activity',
            'type' => 'Create',
            'actor' => $remote->uri,
            'object' => [
                'id' => $noteUri,
                'type' => 'Note',
                'attributedTo' => $remote->uri,
                'content' => '<p>Testo del post.</p>',
                'attachment' => [
                    ['type' => 'Image', 'mediaType' => 'image/jpeg', 'url' => $full],
                    ['type' => 'Image', 'mediaType' => 'image/jpeg', 'url' => $thumb],
                ],
                'published' => now()->toAtomString(),
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ],
        ], $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $post = Post::query()->where('uri', $noteUri)->first();
        $this->assertNotNull($post);
        $this->assertCount(1, $post->media);
        $this->assertSame($full, $post->media->first()->url());
    }

    public function test_a_peertube_style_video_create_with_array_attributed_to_is_stored(): void
    {
        Queue::fake();
        $follower = $this->createFullAccount('ptfollower');
        $person = $this->createRemoteActor('cineasta', 'peertube.example');
        $channel = $this->createRemoteActor('canale', 'peertube.example', [
            'type' => Actor::TYPE_GROUP,
            'name' => 'Canale video',
        ]);
        app(FollowManager::class)->follow($follower->actor, $person)
            ->update(['status' => Follow::STATUS_ACCEPTED, 'accepted_at' => now()]);

        $videoUri = 'https://peertube.example/videos/watch/'.uniqid();
        $watchUrl = 'https://peertube.example/w/'.uniqid();
        $status = $this->process([
            'id' => $videoUri.'/activity',
            'type' => 'Create',
            'actor' => $person->uri,
            'object' => [
                'id' => $videoUri,
                'type' => 'Video',
                'attributedTo' => [
                    ['type' => 'Person', 'id' => $person->uri],
                    ['type' => 'Group', 'id' => $channel->uri],
                ],
                'name' => 'Corto federato',
                'content' => '<p>Descrizione del video</p>',
                'url' => [
                    ['type' => 'Link', 'mediaType' => 'text/html', 'href' => $watchUrl],
                    ['type' => 'Link', 'mediaType' => 'application/x-mpegURL', 'href' => 'https://peertube.example/static/hls.m3u8'],
                ],
                'icon' => [
                    'type' => 'Image',
                    'url' => 'https://peertube.example/lazy-static/thumbnails/poster.jpg',
                ],
                'published' => now()->toAtomString(),
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ],
        ], $person);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $post = Post::query()->where('uri', $videoUri)->first();
        $this->assertNotNull($post);
        $this->assertSame($person->id, $post->actor_id);
        $this->assertSame('Corto federato', $post->title);
        $this->assertSame('Descrizione del video', $post->body);
        $this->assertTrue($post->media->contains(
            fn ($media) => $media->remote_url === 'https://peertube.example/lazy-static/thumbnails/poster.jpg'
        ));
    }

    public function test_a_pixelfed_style_note_with_image_attachment_is_stored(): void
    {
        Queue::fake();
        $follower = $this->createFullAccount('pixfollower');
        $remote = $this->createRemoteActor('fotografo', 'pixelfed.example');
        app(FollowManager::class)->follow($follower->actor, $remote)
            ->update(['status' => Follow::STATUS_ACCEPTED, 'accepted_at' => now()]);

        $noteUri = $remote->uri.'/p/'.uniqid();
        $imageUrl = 'https://pixelfed.example/storage/m/_v2/'.uniqid().'.jpg';
        $status = $this->process([
            'id' => $noteUri.'/activity',
            'type' => 'Create',
            'actor' => $remote->uri,
            'object' => [
                'id' => $noteUri,
                'type' => 'Note',
                'attributedTo' => $remote->uri,
                'content' => '',
                'url' => $noteUri,
                'attachment' => [
                    [
                        'type' => 'Document',
                        'mediaType' => 'image/jpeg',
                        'url' => $imageUrl,
                        'name' => 'Tramonto',
                    ],
                ],
                'published' => now()->toAtomString(),
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ],
        ], $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $post = Post::query()->where('uri', $noteUri)->first();
        $this->assertNotNull($post);
        $this->assertSame($noteUri, $post->body);
        $this->assertCount(1, $post->media);
        $this->assertSame($imageUrl, $post->media->first()->url());
        $this->assertSame('Tramonto', $post->media->first()->alt_text);
    }

    public function test_a_note_with_inline_gif_in_content_stores_media_attachment(): void
    {
        Queue::fake();
        $follower = $this->createFullAccount('giffollower');
        $remote = $this->createRemoteActor('gifposter', 'mastodon.example');
        app(FollowManager::class)->follow($follower->actor, $remote)
            ->update(['status' => Follow::STATUS_ACCEPTED, 'accepted_at' => now()]);

        $noteUri = $remote->uri.'/p/'.uniqid();
        $gifUrl = 'https://mastodon.example/media/'.uniqid().'.gif';
        $status = $this->process([
            'id' => $noteUri.'/activity',
            'type' => 'Create',
            'actor' => $remote->uri,
            'object' => [
                'id' => $noteUri,
                'type' => 'Note',
                'attributedTo' => $remote->uri,
                'content' => '<p>Reazione:</p><p><img src="'.$gifUrl.'" alt="Applausi"></p>',
                'url' => $noteUri,
                'published' => now()->toAtomString(),
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ],
        ], $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $post = Post::query()->where('uri', $noteUri)->first();
        $this->assertNotNull($post);
        $this->assertSame('Reazione:', $post->body);
        $this->assertCount(1, $post->media);
        $this->assertSame($gifUrl, $post->media->first()->url());
        $this->assertSame('image/gif', $post->media->first()->mime_type);
        $this->assertSame('Applausi', $post->media->first()->alt_text);
    }

    public function test_a_mastodon_style_gif_as_mp4_attachment_is_stored(): void
    {
        Queue::fake();
        $follower = $this->createFullAccount('mp4follower');
        $remote = $this->createRemoteActor('gifmp4', 'mastodon.example');
        app(FollowManager::class)->follow($follower->actor, $remote)
            ->update(['status' => Follow::STATUS_ACCEPTED, 'accepted_at' => now()]);

        $noteUri = $remote->uri.'/p/'.uniqid();
        $mp4Url = 'https://mastodon.example/media/'.uniqid().'.mp4';
        $status = $this->process([
            'id' => $noteUri.'/activity',
            'type' => 'Create',
            'actor' => $remote->uri,
            'object' => [
                'id' => $noteUri,
                'type' => 'Note',
                'attributedTo' => $remote->uri,
                'content' => '<p></p>',
                'url' => $noteUri,
                'attachment' => [
                    [
                        'type' => 'Document',
                        'mediaType' => 'video/mp4',
                        'url' => $mp4Url,
                        'name' => 'Applausi',
                    ],
                ],
                'published' => now()->toAtomString(),
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ],
        ], $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $post = Post::query()->where('uri', $noteUri)->first();
        $this->assertNotNull($post);
        $this->assertCount(1, $post->media);
        $this->assertSame($mp4Url, $post->media->first()->url());
        $this->assertSame('video/mp4', $post->media->first()->mime_type);
        $this->assertTrue($post->media->first()->isVideo());
    }

    public function test_a_mastodon_style_audio_attachment_is_stored(): void
    {
        Queue::fake();
        $follower = $this->createFullAccount('audiofollower');
        $remote = $this->createRemoteActor('audioposter', 'mastodon.example');
        app(FollowManager::class)->follow($follower->actor, $remote)
            ->update(['status' => Follow::STATUS_ACCEPTED, 'accepted_at' => now()]);

        $noteUri = $remote->uri.'/p/'.uniqid();
        $mp3Url = 'https://mastodon.example/media/'.uniqid().'.mp3';
        $status = $this->process([
            'id' => $noteUri.'/activity',
            'type' => 'Create',
            'actor' => $remote->uri,
            'object' => [
                'id' => $noteUri,
                'type' => 'Note',
                'attributedTo' => $remote->uri,
                'content' => '<p>#Antistamina clip</p>',
                'url' => $noteUri,
                'attachment' => [
                    [
                        'type' => 'Document',
                        'mediaType' => 'audio/mpeg',
                        'url' => $mp3Url,
                        'name' => 'Antistamina',
                    ],
                ],
                'published' => now()->toAtomString(),
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ],
        ], $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $post = Post::query()->where('uri', $noteUri)->first();
        $this->assertNotNull($post);
        $this->assertCount(1, $post->media);
        $this->assertSame($mp3Url, $post->media->first()->url());
        $this->assertSame('audio/mpeg', $post->media->first()->mime_type);
        $this->assertTrue($post->media->first()->isAudio());
    }

    public function test_a_create_note_replying_to_a_local_post_is_stored_as_a_comment(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('autorerisposta');
        $remote = $this->createRemoteActor('laura');

        $post = Post::query()->create([
            'actor_id' => $author->actor->id,
            'body' => 'Post originale.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $noteUri = $remote->uri.'/posts/'.uniqid();
        $activity = [
            'id' => $noteUri.'/attivita',
            'type' => 'Create',
            'actor' => $remote->uri,
            'object' => [
                'id' => $noteUri,
                'type' => 'Note',
                'attributedTo' => $remote->uri,
                'inReplyTo' => url("/posts/{$post->id}"),
                'content' => 'Bella idea!',
                'published' => now()->toAtomString(),
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ],
        ];

        $status = $this->process($activity, $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertDatabaseHas('comments', [
            'uri' => $noteUri,
            'post_id' => $post->id,
            'actor_id' => $remote->id,
            'body' => 'Bella idea!',
        ]);
        $this->assertSame(1, $post->fresh()->comments_count);
        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $author->id,
            'type' => Notification::TYPE_COMMENT,
        ]);
    }

    public function test_a_remote_reply_with_image_attachment_is_stored_on_the_comment(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('autoreallegato');
        $remote = $this->createRemoteActor('fotoreply', 'pixelfed.example');

        $post = Post::query()->create([
            'actor_id' => $author->actor->id,
            'body' => 'Post originale.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $noteUri = $remote->uri.'/p/'.uniqid();
        $imageUrl = 'https://pixelfed.example/storage/m/_v2/'.uniqid().'.jpg';
        $status = $this->process([
            'id' => $noteUri.'/activity',
            'type' => 'Create',
            'actor' => $remote->uri,
            'object' => [
                'id' => $noteUri,
                'type' => 'Note',
                'attributedTo' => $remote->uri,
                'inReplyTo' => url("/posts/{$post->id}"),
                'content' => 'Ecco la foto.',
                'attachment' => [
                    [
                        'type' => 'Document',
                        'mediaType' => 'image/jpeg',
                        'url' => $imageUrl,
                        'name' => 'Dettaglio',
                    ],
                ],
                'published' => now()->toAtomString(),
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ],
        ], $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $comment = Comment::query()->where('uri', $noteUri)->first();
        $this->assertNotNull($comment);
        $this->assertCount(1, $comment->media);
        $this->assertSame($imageUrl, $comment->media->first()->url());
        $this->assertSame('Dettaglio', $comment->media->first()->alt_text);
    }

    public function test_a_delete_marks_the_remote_cached_post_as_deleted(): void
    {
        Queue::fake();
        $remote = $this->createRemoteActor('mallory');

        $noteUri = $remote->uri.'/posts/'.uniqid();
        $post = Post::query()->create([
            'actor_id' => $remote->id,
            'uri' => $noteUri,
            'body' => 'Contenuto che sparira.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $activity = [
            'id' => $noteUri.'/elimina',
            'type' => 'Delete',
            'actor' => $remote->uri,
            'object' => $noteUri,
        ];

        $status = $this->process($activity, $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $post->refresh();
        $this->assertSame(Post::STATUS_DELETED, $post->status);
        $this->assertSame('', $post->body);
    }

    public function test_an_update_person_from_remote_refreshes_the_cached_actor(): void
    {
        Queue::fake();
        $remote = $this->createRemoteActor('nadia', overrides: [
            'name' => 'Nadia',
            'manually_approves_followers' => false,
        ]);
        $publicKeyPem = $remote->key->public_key;

        $activity = [
            'id' => $remote->uri.'/aggiornamenti/1',
            'type' => 'Update',
            'actor' => $remote->uri,
            'object' => [
                'id' => $remote->uri,
                'type' => 'Person',
                'preferredUsername' => 'nadia',
                'name' => 'Nadia Rinominata',
                'summary' => '<p>Nuova biografia.</p>',
                'icon' => ['type' => 'Image', 'url' => 'https://remoto.example/avatars/nadia.png'],
                'manuallyApprovesFollowers' => true,
                'discoverable' => false,
                'indexable' => true,
                'inbox' => $remote->uri.'/inbox',
                'outbox' => $remote->uri.'/outbox',
                'followers' => $remote->uri.'/followers',
                'following' => $remote->uri.'/following',
                'publicKey' => [
                    'id' => $remote->uri.'#main-key',
                    'owner' => $remote->uri,
                    'publicKeyPem' => $publicKeyPem,
                ],
            ],
        ];

        $status = $this->process($activity, $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $remote->refresh();
        $this->assertSame('Nadia Rinominata', $remote->name);
        $this->assertSame('<p>Nuova biografia.</p>', $remote->summary);
        $this->assertSame('https://remoto.example/avatars/nadia.png', $remote->icon_url);
        $this->assertTrue($remote->manually_approves_followers);
        $this->assertFalse($remote->discoverable);
        $this->assertTrue($remote->indexable);
    }

    public function test_an_update_person_impersonating_another_actor_is_ignored(): void
    {
        Queue::fake();
        $remote = $this->createRemoteActor('oscar');
        $other = $this->createRemoteActor('paula');

        $activity = [
            'id' => $remote->uri.'/aggiornamenti/1',
            'type' => 'Update',
            'actor' => $remote->uri,
            'object' => [
                'id' => $other->uri,
                'type' => 'Person',
                'name' => 'Furto di identita',
                'publicKey' => [
                    'id' => $other->uri.'#main-key',
                    'owner' => $other->uri,
                    'publicKeyPem' => $other->key->public_key,
                ],
            ],
        ];

        $status = $this->process($activity, $remote);

        $this->assertSame(InboxItem::STATUS_IGNORED, $status);
        $this->assertSame('Paula', $other->fresh()->name);
    }

    public function test_an_unknown_signer_is_ignored(): void
    {
        // Nessun Actor "fantasma" in cache: il resolver tentera' di
        // recuperarlo, simuliamo una risposta 404 cosi' resta sconosciuto
        // senza eseguire una richiesta di rete reale.
        Http::fake(['*' => Http::response('', 404)]);

        $target = $this->createFullAccount('senzasigner');

        $item = InboxItem::query()->create([
            'is_shared' => false,
            'remote_activity_uri' => 'https://remoto.example/activities/inesistente',
            'activity_type' => 'Follow',
            'actor_uri' => 'https://remoto.example/users/fantasma',
            'payload' => json_encode([
                'id' => 'https://remoto.example/activities/inesistente',
                'type' => 'Follow',
                'actor' => 'https://remoto.example/users/fantasma',
                'object' => $target->actor->uri,
            ], JSON_THROW_ON_ERROR),
            'signature_valid' => true,
            'status' => InboxItem::STATUS_PENDING,
            'received_at' => now(),
        ]);

        $status = app(InboxActivityProcessor::class)->process($item);

        $this->assertSame(InboxItem::STATUS_IGNORED, $status);
        $this->assertDatabaseCount('follows', 0);
    }
}
