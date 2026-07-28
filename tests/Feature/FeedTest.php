<?php

namespace Tests\Feature;

use App\Application\Queries\FeedQuery;
use App\Application\Services\AnnounceManager;
use App\Application\Services\FollowManager;
use App\Application\Services\PostComposer;
use App\Domain\Accounts\User;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class FeedTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function publishPost(User $author, string $body, string $visibility = Post::VISIBILITY_PUBLIC): Post
    {
        return app(PostComposer::class)->compose($author->actor, [
            'body' => $body,
            'visibility' => $visibility,
        ]);
    }

    public function test_the_feed_includes_the_viewers_own_posts(): void
    {
        $user = $this->createFullAccount('feedown');
        $post = $this->publishPost($user, 'Il mio post.');

        $feed = app(FeedQuery::class)->forActor($user->actor);

        $this->assertTrue($feed->getCollection()->contains('id', $post->id));
    }

    public function test_the_feed_includes_posts_from_followed_actors(): void
    {
        $viewer = $this->createFullAccount('feedviewer');
        $followed = $this->createFullAccount('feedfollowed');
        $stranger = $this->createFullAccount('feedstranger');

        app(FollowManager::class)->follow($viewer->actor, $followed->actor);

        $followedPost = $this->publishPost($followed, 'Post di chi seguo.');
        $strangerPost = $this->publishPost($stranger, 'Post di un estraneo.');

        $feed = app(FeedQuery::class)->forActor($viewer->actor);
        $ids = $feed->getCollection()->pluck('id');

        $this->assertTrue($ids->contains($followedPost->id));
        $this->assertFalse($ids->contains($strangerPost->id));
    }

    public function test_the_feed_includes_posts_announced_by_followed_actors(): void
    {
        $viewer = $this->createFullAccount('feedviewer2');
        $followed = $this->createFullAccount('feedfollowed2');
        $original = $this->createFullAccount('feedoriginal2');

        app(FollowManager::class)->follow($viewer->actor, $followed->actor);

        $post = $this->publishPost($original, 'Post condiviso.');
        app(AnnounceManager::class)->announce($followed->actor, $post);

        $feed = app(FeedQuery::class)->forActor($viewer->actor);

        $this->assertTrue($feed->getCollection()->pluck('id')->contains($post->id));
    }

    public function test_the_feed_excludes_followers_only_posts_from_non_followers(): void
    {
        $viewer = $this->createFullAccount('feedviewer3');
        $author = $this->createFullAccount('feedauthor3');

        // Il "viewer" non segue "author": il post riservato ai follower non deve comparire,
        // anche se per qualche motivo apparisse tra i post rilevanti.
        $post = $this->publishPost($author, 'Riservato ai follower.', Post::VISIBILITY_FOLLOWERS);

        $feed = app(FeedQuery::class)->forActor($viewer->actor);

        $this->assertFalse($feed->getCollection()->pluck('id')->contains($post->id));
    }

    public function test_the_feed_is_ordered_from_newest_to_oldest(): void
    {
        $user = $this->createFullAccount('feedorder');

        $first = $this->publishPost($user, 'Primo post.');
        $first->update(['published_at' => now()->subHour()]);
        $second = $this->publishPost($user, 'Secondo post.');

        $feed = app(FeedQuery::class)->forActor($user->actor);
        $ids = $feed->getCollection()->pluck('id')->values();

        $this->assertSame($second->id, $ids->first());
    }

    public function test_the_local_feed_only_shows_public_posts(): void
    {
        $user = $this->createFullAccount('feedlocal');
        $publicPost = $this->publishPost($user, 'Pubblico.');
        $unlistedPost = $this->publishPost($user, 'Non elencato.', Post::VISIBILITY_UNLISTED);

        $localFeed = app(FeedQuery::class)->local();
        $ids = $localFeed->getCollection()->pluck('id');

        $this->assertTrue($ids->contains($publicPost->id));
        $this->assertFalse($ids->contains($unlistedPost->id));
    }

    public function test_the_authenticated_feed_page_renders_posts(): void
    {
        $user = $this->createFullAccount('feedhttp');
        $this->publishPost($user, 'Post visibile nella pagina feed.');

        $this->actingAs($user)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertSee('Post visibile nella pagina feed.');
    }
}
