<?php

namespace Tests\Feature\Federation;

use App\Application\Services\AnnounceManager;
use App\Application\Services\CommentComposer;
use App\Application\Services\FollowManager;
use App\Application\Services\PostComposer;
use App\Application\Services\ReactionManager;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

/**
 * Verifica che le azioni locali (follow, mi piace, condivisione,
 * pubblicazione/eliminazione di post e commenti) attivino la consegna
 * federata corretta quando coinvolgono un Actor remoto, riusando sempre gli
 * stessi servizi applicativi del percorso locale (Fase 4). Nessuna
 * richiesta di rete reale: Queue::fake() intercetta i job di consegna.
 */
class OutgoingActivityTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    private function assertActivityDispatchedTo(string $inboxUrl, string $activityType): void
    {
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->inboxUrl === $inboxUrl && $job->activity['type'] === $activityType);
    }

    public function test_following_a_remote_actor_dispatches_a_follow_activity(): void
    {
        Queue::fake();
        $local = $this->createFullAccount('richiedentefollow');
        $remote = $this->createRemoteActor('marco');

        app(FollowManager::class)->follow($local->actor, $remote);

        $this->assertActivityDispatchedTo($remote->endpoints->shared_inbox ?: $remote->endpoints->inbox, 'Follow');
    }

    public function test_retrying_a_pending_remote_follow_redispatches_the_follow_activity(): void
    {
        Queue::fake();
        $local = $this->createFullAccount('retryfollow');
        $remote = $this->createRemoteActor('pietro');

        app(FollowManager::class)->follow($local->actor, $remote);
        app(FollowManager::class)->follow($local->actor, $remote);

        Queue::assertPushed(DeliverActivityJob::class, 2);
        Queue::assertPushed(
            DeliverActivityJob::class,
            fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Follow'
                && ($job->activity['to'][0] ?? null) === $remote->uri
        );
    }

    public function test_unfollowing_a_remote_actor_dispatches_an_undo_follow_activity(): void
    {
        Queue::fake();
        $local = $this->createFullAccount('exfollower');
        $remote = $this->createRemoteActor('nadia');

        app(FollowManager::class)->follow($local->actor, $remote);
        app(FollowManager::class)->unfollow($local->actor, $remote);

        $this->assertActivityDispatchedTo($remote->endpoints->shared_inbox ?: $remote->endpoints->inbox, 'Undo');
    }

    public function test_liking_content_authored_by_a_remote_actor_dispatches_a_like_activity(): void
    {
        Queue::fake();
        $remoteAuthor = $this->createRemoteActor('olga');
        $liker = $this->createFullAccount('personacheametta');

        $post = Post::query()->create([
            'actor_id' => $remoteAuthor->id,
            'uri' => $remoteAuthor->uri.'/posts/1',
            'body' => 'Post remoto in cache.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        app(ReactionManager::class)->like($liker->actor, $post);

        $this->assertActivityDispatchedTo($remoteAuthor->endpoints->shared_inbox ?: $remoteAuthor->endpoints->inbox, 'Like');
    }

    public function test_unliking_content_authored_by_a_remote_actor_dispatches_an_undo_like_activity(): void
    {
        Queue::fake();
        $remoteAuthor = $this->createRemoteActor('paul');
        $liker = $this->createFullAccount('personachesidisama');

        $post = Post::query()->create([
            'actor_id' => $remoteAuthor->id,
            'uri' => $remoteAuthor->uri.'/posts/2',
            'body' => 'Altro post remoto in cache.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        app(ReactionManager::class)->like($liker->actor, $post);
        app(ReactionManager::class)->unlike($liker->actor, $post);

        $this->assertActivityDispatchedTo($remoteAuthor->endpoints->shared_inbox ?: $remoteAuthor->endpoints->inbox, 'Undo');
    }

    public function test_announcing_a_remote_authored_post_notifies_the_original_author_directly(): void
    {
        Queue::fake();
        $remoteAuthor = $this->createRemoteActor('quinn');
        $sharer = $this->createFullAccount('condivisore');

        $post = Post::query()->create([
            'actor_id' => $remoteAuthor->id,
            'uri' => $remoteAuthor->uri.'/posts/3',
            'body' => 'Post da rilanciare.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        app(AnnounceManager::class)->announce($sharer->actor, $post);

        $this->assertActivityDispatchedTo($remoteAuthor->endpoints->shared_inbox ?: $remoteAuthor->endpoints->inbox, 'Announce');
    }

    public function test_unannouncing_a_remote_authored_post_dispatches_an_undo_announce_activity(): void
    {
        Queue::fake();
        $remoteAuthor = $this->createRemoteActor('rita');
        $sharer = $this->createFullAccount('excondivisore');

        $post = Post::query()->create([
            'actor_id' => $remoteAuthor->id,
            'uri' => $remoteAuthor->uri.'/posts/4',
            'body' => 'Post da rilanciare e poi ritirare.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        app(AnnounceManager::class)->announce($sharer->actor, $post);
        app(AnnounceManager::class)->unannounce($sharer->actor, $post);

        $this->assertActivityDispatchedTo($remoteAuthor->endpoints->shared_inbox ?: $remoteAuthor->endpoints->inbox, 'Undo');
    }

    public function test_publishing_a_local_post_delivers_a_create_activity_to_a_remote_follower(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('autorepubblicante');
        $follower = $this->createRemoteActor('sam');

        Follow::query()->create([
            'follower_id' => $follower->id,
            'following_id' => $author->actor->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        app(PostComposer::class)->compose($author->actor, ['body' => 'Il mio primo post federato!']);

        $this->assertActivityDispatchedTo($follower->endpoints->shared_inbox, 'Create');
    }

    public function test_editing_a_local_post_delivers_an_update_activity_to_a_remote_follower(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('autoremodificante');
        $follower = $this->createRemoteActor('uma');

        Follow::query()->create([
            'follower_id' => $follower->id,
            'following_id' => $author->actor->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        $post = app(PostComposer::class)->compose($author->actor, ['body' => 'Versione iniziale federata.']);

        Queue::fake();

        $this->actingAs($author)->put(route('posts.update', $post), [
            'body' => 'Versione aggiornata federata.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ])->assertRedirect(route('posts.show', $post));

        $this->assertActivityDispatchedTo($follower->endpoints->shared_inbox, 'Update');
    }

    public function test_deleting_a_local_post_delivers_a_delete_activity_to_a_remote_follower(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('autoreeliminante');
        $follower = $this->createRemoteActor('tara');

        Follow::query()->create([
            'follower_id' => $follower->id,
            'following_id' => $author->actor->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        $post = Post::query()->create([
            'actor_id' => $author->actor->id,
            'body' => 'Post che verra cancellato.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $this->actingAs($author)->delete(route('posts.destroy', $post))->assertRedirect();

        $this->assertActivityDispatchedTo($follower->endpoints->shared_inbox, 'Delete');
    }

    public function test_replying_to_a_remote_authored_post_notifies_its_author_directly(): void
    {
        Queue::fake();
        $remoteAuthor = $this->createRemoteActor('ursula');
        $commenter = $this->createFullAccount('rispositore');

        $post = Post::query()->create([
            'actor_id' => $remoteAuthor->id,
            'uri' => $remoteAuthor->uri.'/posts/5',
            'body' => 'Post remoto a cui rispondere.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        app(CommentComposer::class)->compose($commenter->actor, $post, 'Ottimo spunto!');

        $this->assertActivityDispatchedTo($remoteAuthor->endpoints->shared_inbox ?: $remoteAuthor->endpoints->inbox, 'Create');
    }

    public function test_a_reply_to_a_remote_post_includes_an_implicit_mention_in_the_create_activity(): void
    {
        Queue::fake();
        $remoteAuthor = $this->createRemoteActor('wendy');
        $commenter = $this->createFullAccount('rispositoreimplicito');

        $post = Post::query()->create([
            'actor_id' => $remoteAuthor->id,
            'uri' => $remoteAuthor->uri.'/posts/7',
            'body' => 'Post remoto senza chiocciola in reply.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        app(CommentComposer::class)->compose($commenter->actor, $post, 'Ottimo spunto senza menzione!');

        Queue::assertPushed(DeliverActivityJob::class, function (DeliverActivityJob $job) use ($remoteAuthor): bool {
            if (($job->activity['type'] ?? null) !== 'Create') {
                return false;
            }

            $object = is_array($job->activity['object'] ?? null) ? $job->activity['object'] : [];
            $tags = is_array($object['tag'] ?? null) ? $object['tag'] : [];
            $hasMention = collect($tags)->contains(
                fn ($tag): bool => is_array($tag)
                    && ($tag['type'] ?? null) === 'Mention'
                    && ($tag['href'] ?? null) === $remoteAuthor->uri
            );

            return $hasMention
                && str_contains((string) ($object['content'] ?? ''), $remoteAuthor->uri)
                && in_array($remoteAuthor->uri, $job->activity['cc'] ?? [], true);
        });
    }

    public function test_deleting_a_reply_to_a_remote_authored_post_notifies_its_author_directly(): void
    {
        Queue::fake();
        $remoteAuthor = $this->createRemoteActor('victor');
        $commenter = $this->createFullAccount('rispositoreeliminante');

        $post = Post::query()->create([
            'actor_id' => $remoteAuthor->id,
            'uri' => $remoteAuthor->uri.'/posts/6',
            'body' => 'Post remoto con risposta da eliminare.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $comment = app(CommentComposer::class)->compose($commenter->actor, $post, 'Ci ripenso...');

        $this->actingAs($commenter)->delete(route('comments.destroy', $comment))->assertRedirect();

        $this->assertActivityDispatchedTo($remoteAuthor->endpoints->shared_inbox ?: $remoteAuthor->endpoints->inbox, 'Delete');
    }
}
