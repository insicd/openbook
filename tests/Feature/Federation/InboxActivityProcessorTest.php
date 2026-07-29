<?php

namespace Tests\Feature\Federation;

use App\Application\Services\FollowManager;
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

        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->inboxUrl === $remote->endpoints->inbox
            && $job->activity['type'] === 'Accept'
            && $job->signingActorId === $target->actor->id);
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
