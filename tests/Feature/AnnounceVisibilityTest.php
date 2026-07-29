<?php

namespace Tests\Feature;

use App\Application\Queries\FeedQuery;
use App\Application\Services\AnnounceManager;
use App\Application\Services\PostComposer;
use App\Domain\Accounts\User;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

/**
 * Chi condivide un post (proprio, di un altro Actor locale, o di un Actor
 * remoto) deve vederlo comparire nel proprio profilo e nel proprio feed
 * personale con l'indicazione "ha condiviso" — non solo nel feed di chi lo
 * segue, che era l'unico posto in cui compariva prima di questa modifica
 * (vedi FeedQuery::forProfile()).
 */
class AnnounceVisibilityTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    private function publishPost(User $author, string $body): Post
    {
        return app(PostComposer::class)->compose($author->actor, [
            'body' => $body,
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
    }

    public function test_sharing_a_remote_post_makes_it_appear_on_the_sharers_own_profile(): void
    {
        Queue::fake();
        $sharer = $this->createFullAccount('condivisore1');
        $remote = $this->createRemoteActor('originale1');
        $post = Post::query()->create([
            'actor_id' => $remote->id,
            'uri' => $remote->uri.'/posts/1',
            'body' => 'Post del fediverso.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);

        app(AnnounceManager::class)->announce($sharer->actor, $post);

        $response = $this->actingAs($sharer)->get(route('profile.show', $sharer->username));

        $response->assertOk();
        $response->assertSee('Post del fediverso.');
        $response->assertSee(__('openbook.actions.shared_this'));
    }

    public function test_sharing_a_post_from_another_local_actor_makes_it_appear_on_the_profile(): void
    {
        $sharer = $this->createFullAccount('condivisore2');
        $original = $this->createFullAccount('originale2');
        $post = $this->publishPost($original, 'Post originale di un altro utente locale.');

        app(AnnounceManager::class)->announce($sharer->actor, $post);

        $posts = app(FeedQuery::class)->forProfile($sharer->actor, $sharer->actor);

        $this->assertTrue($posts->getCollection()->pluck('id')->contains($post->id));

        $shared = $posts->getCollection()->firstWhere('id', $post->id);
        $this->assertNotNull($shared->sharedBy);
        $this->assertSame($sharer->actor->id, $shared->sharedBy->id);
    }

    public function test_a_self_share_does_not_produce_a_redundant_shared_by_label(): void
    {
        $author = $this->createFullAccount('autocondivisione');
        $post = $this->publishPost($author, 'Condivido il mio stesso post.');

        app(AnnounceManager::class)->announce($author->actor, $post);

        $posts = app(FeedQuery::class)->forProfile($author->actor, $author->actor);
        $ownPost = $posts->getCollection()->firstWhere('id', $post->id);

        $this->assertSame(1, $posts->getCollection()->where('id', $post->id)->count());
        $this->assertNull($ownPost->sharedBy);
    }

    public function test_unsharing_removes_the_post_from_the_sharers_profile(): void
    {
        $sharer = $this->createFullAccount('condivisore3');
        $original = $this->createFullAccount('originale3');
        $post = $this->publishPost($original, 'Post che verra scondiviso.');

        $manager = app(AnnounceManager::class);
        $manager->announce($sharer->actor, $post);
        $manager->unannounce($sharer->actor, $post);

        $posts = app(FeedQuery::class)->forProfile($sharer->actor, $sharer->actor);

        $this->assertFalse($posts->getCollection()->pluck('id')->contains($post->id));
    }

    public function test_a_freshly_shared_old_post_appears_before_the_sharers_more_recent_own_post(): void
    {
        $sharer = $this->createFullAccount('condivisore4');
        $original = $this->createFullAccount('originale4');

        $oldPost = $this->publishPost($original, 'Post vecchio di dieci giorni.');
        $oldPost->update(['published_at' => now()->subDays(10)]);

        $ownRecentPost = $this->publishPost($sharer, 'Il mio post di due ore fa.');
        $ownRecentPost->update(['published_at' => now()->subHours(2)]);

        app(AnnounceManager::class)->announce($sharer->actor, $oldPost);

        $posts = app(FeedQuery::class)->forProfile($sharer->actor, $sharer->actor);
        $ids = $posts->getCollection()->pluck('id')->values();

        $this->assertSame([$oldPost->id, $ownRecentPost->id], $ids->all());
    }

    public function test_the_home_feed_also_shows_who_shared_a_post(): void
    {
        $viewer = $this->createFullAccount('condivisore5');
        $original = $this->createFullAccount('originale5');
        $post = $this->publishPost($original, 'Post condiviso mostrato in home.');

        app(AnnounceManager::class)->announce($viewer->actor, $post);

        $feed = app(FeedQuery::class)->forActor($viewer->actor);
        $shared = $feed->getCollection()->firstWhere('id', $post->id);

        $this->assertNotNull($shared);
        $this->assertNotNull($shared->sharedBy);
        $this->assertSame($viewer->actor->id, $shared->sharedBy->id);
    }

    public function test_viewing_the_original_authors_profile_does_not_show_a_shared_by_label(): void
    {
        $sharer = $this->createFullAccount('condivisore6');
        $original = $this->createFullAccount('originale6');
        $post = $this->publishPost($original, 'Post visto sul profilo del suo vero autore.');

        app(AnnounceManager::class)->announce($sharer->actor, $post);

        $posts = app(FeedQuery::class)->forProfile($original->actor, $original->actor);
        $ownPost = $posts->getCollection()->firstWhere('id', $post->id);

        $this->assertNull($ownPost->sharedBy);
    }
}
