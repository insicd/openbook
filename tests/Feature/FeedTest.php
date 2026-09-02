<?php

namespace Tests\Feature;

use App\Application\Queries\FeedQuery;
use App\Application\Services\AnnounceManager;
use App\Application\Services\CommunityMembershipService;
use App\Application\Services\CommunityRegistrar;
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

    public function test_the_feed_deduplicates_a_community_announce_and_paginates_from_its_share_time(): void
    {
        $viewer = $this->createFullAccount('feedeventviewer');
        $author = $this->createFullAccount('feedeventauthor');
        $community = app(CommunityRegistrar::class)->register($author, [
            'slug' => 'feedeventcommunity',
            'name' => 'Community eventi feed',
        ]);

        app(FollowManager::class)->follow($viewer->actor, $author->actor);
        app(CommunityMembershipService::class)->join($viewer->actor, $community);

        $ordinaryPost = $this->publishPost($author, 'Post ordinario piu recente.');
        $ordinaryPost->update(['published_at' => now()->subHour()]);

        $communityPost = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Post vecchio annunciato dalla community.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'community_id' => $community->id,
        ]);
        $communityPost->update(['published_at' => now()->subDays(10)]);

        $all = app(FeedQuery::class)->forActor($viewer->actor, perPage: 3)->getCollection();

        $this->assertSame(1, $all->where('id', $communityPost->id)->count());
        $this->assertSame($community->actor_id, $all->firstWhere('id', $communityPost->id)?->sharedBy?->id);
        $this->assertSame($communityPost->id, $all->first()->id);

        $firstPage = app(FeedQuery::class)->forActor($viewer->actor, perPage: 1);
        $cursor = \App\Application\Queries\FeedCursor::fromPost($firstPage->getCollection()->sole(), useShareSort: true);
        $secondPage = app(FeedQuery::class)->forActor($viewer->actor, $cursor, perPage: 1);

        $this->assertSame($communityPost->id, $firstPage->getCollection()->sole()->id);
        $this->assertSame($ordinaryPost->id, $secondPage->getCollection()->sole()->id);
    }

    public function test_the_feed_uses_the_latest_relevant_announce_for_ordering_and_attribution(): void
    {
        $viewer = $this->createFullAccount('feedlatestviewer');
        $firstSharer = $this->createFullAccount('feedlatestfirst');
        $latestSharer = $this->createFullAccount('feedlatestlast');
        $author = $this->createFullAccount('feedlatestauthor');

        app(FollowManager::class)->follow($viewer->actor, $firstSharer->actor);
        app(FollowManager::class)->follow($viewer->actor, $latestSharer->actor);
        app(FollowManager::class)->follow($viewer->actor, $author->actor);

        $sharedPost = $this->publishPost($author, 'Post condiviso da due persone.');
        $sharedPost->update(['published_at' => now()->subDays(7)]);
        app(AnnounceManager::class)->announce($firstSharer->actor, $sharedPost, occurredAt: now()->subHours(2));
        app(AnnounceManager::class)->announce($latestSharer->actor, $sharedPost, occurredAt: now());

        $ordinaryPost = $this->publishPost($author, 'Post ordinario fra le due condivisioni.');
        $ordinaryPost->update(['published_at' => now()->subHour()]);

        $items = app(FeedQuery::class)->forActor($viewer->actor, perPage: 3)->getCollection();
        $resolvedSharedPost = $items->firstWhere('id', $sharedPost->id);

        $this->assertSame($sharedPost->id, $items->first()->id);
        $this->assertSame(1, $items->where('id', $sharedPost->id)->count());
        $this->assertSame($latestSharer->actor->id, $resolvedSharedPost?->sharedBy?->id);
        $this->assertSame($ordinaryPost->id, $items->last()->id);
    }

    public function test_an_announce_does_not_bypass_direct_post_visibility(): void
    {
        $viewer = $this->createFullAccount('feedprivateviewer');
        $sharer = $this->createFullAccount('feedprivatesharer');
        $author = $this->createFullAccount('feedprivateauthor');
        $recipient = $this->createFullAccount('feedprivaterecipient');

        app(FollowManager::class)->follow($viewer->actor, $sharer->actor);

        $directPost = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Messaggio diretto che non deve diventare pubblico.',
            'visibility' => Post::VISIBILITY_DIRECT,
        ]);
        app(AnnounceManager::class)->announce($sharer->actor, $directPost);

        $postIds = app(FeedQuery::class)->forActor($viewer->actor)->getCollection()->pluck('id');

        $this->assertFalse($postIds->contains($directPost->id));
    }

    public function test_the_feed_applies_non_public_visibility_rules_per_relevant_actor(): void
    {
        $viewer = $this->createFullAccount('feedvisibilityviewer');
        $otherFollower = $this->createFullAccount('feedvisibilityother');
        $author = $this->createFullAccount('feedvisibilityauthor');

        app(FollowManager::class)->follow($viewer->actor, $author->actor);
        app(FollowManager::class)->follow($otherFollower->actor, $author->actor);

        $followersPost = $this->publishPost($author, 'Post per i follower.', Post::VISIBILITY_FOLLOWERS);
        $directPost = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Messaggio diretto per @feedvisibilityviewer.',
            'visibility' => Post::VISIBILITY_DIRECT,
        ]);

        $community = app(CommunityRegistrar::class)->register($author, [
            'slug' => 'feedvisibilityprivate',
            'name' => 'Community privata del feed',
            'is_private' => true,
        ]);
        $membership = app(CommunityMembershipService::class)->join($viewer->actor, $community);
        app(FollowManager::class)->accept($community->actor, $membership->follower);

        $privateCommunityPost = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Post nella community privata.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'community_id' => $community->id,
        ]);

        $viewerPostIds = app(FeedQuery::class)->forActor($viewer->actor)->getCollection()->pluck('id');
        $otherFollowerPostIds = app(FeedQuery::class)->forActor($otherFollower->actor)->getCollection()->pluck('id');

        $this->assertTrue($viewerPostIds->contains($followersPost->id));
        $this->assertTrue($viewerPostIds->contains($directPost->id));
        $this->assertTrue($viewerPostIds->contains($privateCommunityPost->id));
        $this->assertTrue($otherFollowerPostIds->contains($followersPost->id));
        $this->assertFalse($otherFollowerPostIds->contains($directPost->id));
        $this->assertFalse($otherFollowerPostIds->contains($privateCommunityPost->id));
    }

    public function test_the_feed_excludes_private_conversation_messages(): void
    {
        $viewer = $this->createFullAccount('feedviewer_dm');
        $recipient = $this->createFullAccount('feedrecipient_dm');

        $publicPost = $this->publishPost($viewer, 'Post pubblico nel feed.');
        $dm = app(\App\Application\Services\MessageComposer::class)->send(
            $viewer->actor,
            $recipient->actor,
            'Messaggio privato fuori feed',
        );

        $feed = app(FeedQuery::class)->forActor($viewer->actor);
        $profileFeed = app(FeedQuery::class)->forProfile($viewer->actor, $viewer->actor);
        $ids = $feed->getCollection()->pluck('id');

        $this->assertTrue($ids->contains($publicPost->id));
        $this->assertFalse($ids->contains($dm->id));
        $this->assertFalse($profileFeed->getCollection()->pluck('id')->contains($dm->id));
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

    public function test_long_post_bodies_are_truncated_in_the_feed_with_a_read_more_control(): void
    {
        $user = $this->createFullAccount('feedexcerpt');
        $prefix = str_repeat('a', 150);
        $suffix = ' PARTE_NASCOSTA_NEL_FEED';
        $post = $this->publishPost($user, $prefix.$suffix);

        $feed = $this->actingAs($user)->get(route('feed.index'));
        $feed->assertOk();
        $feed->assertSee(__('openbook.posts.read_more'), false);
        $feed->assertSee('ob-post__excerpt', false);
        $feed->assertSee($prefix, false);

        $detail = $this->actingAs($user)->get(route('posts.show', $post));
        $detail->assertOk();
        $detail->assertSee($suffix, false);
        $detail->assertDontSee(__('openbook.posts.read_more'), false);
        $detail->assertDontSee('ob-post__excerpt', false);
    }
}
