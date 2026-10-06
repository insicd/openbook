<?php

namespace Tests\Feature;

use App\Application\Queries\FeedQuery;
use App\Application\Queries\RemotePostRetentionQuery;
use App\Application\Services\InstanceSettings;
use App\Domain\Comments\Comment;
use App\Domain\Communities\Community;
use App\Domain\Messaging\Conversation;
use App\Domain\Posts\Post;
use App\Domain\Reactions\Announce;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Infrastructure\Database\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class RemotePostRetentionQueryTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
    }

    private function enable(int $short = 30, int $long = 90): void
    {
        SystemSetting::put(InstanceSettings::KEY_REMOTE_POST_NON_PERTINENT_RETENTION_DAYS, (string) $short);
        SystemSetting::put(InstanceSettings::KEY_REMOTE_POST_PERTINENT_RETENTION_DAYS, (string) $long);
    }

    private function remote(string $type = Actor::TYPE_PERSON): Actor
    {
        $name = 'r'.Str::random(12);

        return Actor::query()->create([
            'type' => $type, 'is_local' => false, 'preferred_username' => $name,
            'domain' => 'remote.test', 'uri' => 'https://remote.test/users/'.$name,
        ]);
    }

    private function fixturePost(Actor $actor, int $age = 100, array $attributes = []): Post
    {
        $post = Post::query()->create(array_merge([
            'actor_id' => $actor->id, 'body' => 'Retention fixture',
            'uri' => $actor->is_local ? null : 'https://remote.test/posts/'.Str::uuid(),
            'published_at' => now()->subYears(5), 'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
        ], $attributes));
        $post->forceFill(['created_at' => now()->subDays($age)])->saveQuietly();

        return $post;
    }

    private function follow(Actor $viewer, Actor $source, string $status = Follow::STATUS_ACCEPTED): void
    {
        Follow::query()->create([
            'follower_id' => $viewer->id, 'following_id' => $source->id,
            'status' => $status, 'requested_at' => now(),
        ]);
    }

    private function ids(bool $pertinent): array
    {
        $query = app(RemotePostRetentionQuery::class);

        return ($pertinent ? $query->pertinent(200) : $query->nonPertinent(200))->pluck('id')->all();
    }

    public function test_defaults_disable_both_categories_and_each_zero_is_independent(): void
    {
        $viewer = $this->createFullAccount('retentiondefaults')->actor;
        $remote = $this->remote();
        $followed = $this->fixturePost($remote);
        $world = $this->fixturePost($this->remote());
        $this->follow($viewer, $remote);
        $this->assertSame(0, app(InstanceSettings::class)->remotePostPertinentRetentionDays());
        $this->assertSame(0, app(InstanceSettings::class)->remotePostNonPertinentRetentionDays());
        $this->assertSame([], $this->ids(true));
        $this->assertSame([], $this->ids(false));
        $this->enable(30, 0);
        $this->assertSame([$world->id], $this->ids(false));
        $this->assertSame([], $this->ids(true));
        $this->enable(0, 90);
        $this->assertSame([$followed->id], $this->ids(true));
        $this->assertSame([], $this->ids(false));
    }

    public function test_age_is_import_time_with_strict_cutoff_stable_order_and_limit(): void
    {
        $this->enable();
        $author = $this->remote();
        $old = $this->fixturePost($author, 40);
        $other = $this->fixturePost($author, 40);
        $boundary = $this->fixturePost($author, 30);
        $fresh = $this->fixturePost($author, 1);
        $expected = [$old->id, $other->id];
        sort($expected);
        $this->assertSame($expected, $this->ids(false));
        $this->assertSame([$expected[0]], app(RemotePostRetentionQuery::class)->nonPertinent(1)->pluck('id')->all());
        $this->assertDatabaseHas('posts', ['id' => $boundary->id]);
        $this->assertDatabaseHas('posts', ['id' => $fresh->id]);
        $this->assertSame(4, Post::query()->count());
    }

    public function test_local_posts_direct_messages_and_legacy_direct_records_are_excluded(): void
    {
        $this->enable();
        $user = $this->createFullAccount('retentionlocal');
        $this->fixturePost($user->actor, attributes: ['uri' => 'https://openbook.test/local-note']);
        $remote = $this->remote();
        $this->fixturePost($remote, attributes: ['uri' => null, 'source_uri' => 'https://blog.test/entry']);
        $this->fixturePost($remote, attributes: ['visibility' => Post::VISIBILITY_DIRECT]);
        $conversation = Conversation::query()->create([
            'participant_low_id' => $user->actor->id, 'participant_high_id' => $remote->id,
        ]);
        $this->fixturePost($remote, attributes: ['conversation_id' => $conversation->id]);
        $this->assertSame([], $this->ids(true));
        $this->assertSame([], $this->ids(false));
    }

    public function test_home_sources_match_feed_including_own_boost_and_group_announce(): void
    {
        $this->enable();
        $viewer = $this->createFullAccount('retentionhome')->actor;
        $author = $this->remote();
        $this->follow($viewer, $author);
        $byAuthor = $this->fixturePost($author);
        $ownBoost = $this->fixturePost($this->remote());
        Announce::query()->create(['actor_id' => $viewer->id, 'post_id' => $ownBoost->id, 'is_direct' => true]);
        $sharer = $this->remote();
        $this->follow($viewer, $sharer);
        $boost = $this->fixturePost($this->remote());
        Announce::query()->create(['actor_id' => $sharer->id, 'post_id' => $boost->id, 'is_direct' => true]);
        $group = $this->remote(Actor::TYPE_GROUP);
        $this->follow($viewer, $group);
        $groupPost = $this->fixturePost($this->remote());
        Announce::query()->create(['actor_id' => $group->id, 'post_id' => $groupPost->id, 'is_direct' => false]);
        $tagged = $this->fixturePost($this->remote());
        $tag = (string) Str::uuid();
        DB::table('hashtags')->insert(['id' => $tag, 'name' => 'retention']);
        DB::table('post_hashtags')->insert(['post_id' => $tagged->id, 'hashtag_id' => $tag]);
        DB::table('hashtag_follows')->insert(['actor_id' => $viewer->id, 'hashtag_id' => $tag]);
        $expected = [$byAuthor->id, $ownBoost->id, $boost->id, $groupPost->id, $tagged->id];
        $this->assertEqualsCanonicalizing($expected, $this->ids(true));
        $this->assertSame([], $this->ids(false));
        $this->assertEqualsCanonicalizing($expected, app(FeedQuery::class)->forActor($viewer, perPage: 200)->getCollection()->pluck('id')->all());
    }

    public function test_unfollow_promotes_no_history_and_pending_follows_quotes_likes_do_not_create_home_events(): void
    {
        $this->enable();
        $viewer = $this->createFullAccount('retentionpending')->actor;
        $author = $this->remote();
        $this->follow($viewer, $author, Follow::STATUS_PENDING);
        $post = $this->fixturePost($author);
        Announce::query()->create(['actor_id' => $viewer->id, 'post_id' => $post->id, 'is_direct' => false]);
        DB::table('likes')->insert(['id' => (string) Str::uuid(), 'actor_id' => $viewer->id, 'likeable_type' => 'post', 'likeable_id' => $post->id]);
        $this->assertSame([], $this->ids(true));
        $this->assertSame([$post->id], $this->ids(false));
        Follow::query()->update(['status' => Follow::STATUS_ACCEPTED]);
        $this->assertSame([$post->id], $this->ids(true));
        $this->assertSame([], $this->ids(false));
        Follow::query()->delete();
        $this->assertSame([$post->id], $this->ids(false));
    }

    public function test_local_comments_at_any_depth_make_remote_posts_pertinent_even_if_deleted(): void
    {
        $this->enable();
        $local = $this->createFullAccount('retentioncomment')->actor;
        $remote = $this->remote();
        $post = $this->fixturePost($remote, attributes: ['status' => Post::STATUS_DELETED]);
        $parent = Comment::query()->create(['post_id' => $post->id, 'actor_id' => $remote->id, 'body' => 'remote', 'status' => 'published']);
        Comment::query()->create(['post_id' => $post->id, 'parent_comment_id' => $parent->id, 'actor_id' => $local->id, 'body' => '', 'status' => 'deleted']);
        $world = $this->fixturePost($remote, attributes: ['status' => Post::STATUS_DELETED]);
        Comment::query()->create(['post_id' => $world->id, 'actor_id' => $remote->id, 'body' => 'recent remote', 'status' => 'published']);
        $this->assertSame([$post->id], $this->ids(true));
        $this->assertSame([$world->id], $this->ids(false));
    }

    public function test_local_quotes_including_deleted_and_private_messages_protect_originals(): void
    {
        $this->enable();
        $local = $this->createFullAccount('retentionquote')->actor;
        $original = $this->fixturePost($this->remote());
        $this->fixturePost($local, attributes: ['quoted_post_id' => $original->id, 'status' => Post::STATUS_DELETED, 'visibility' => Post::VISIBILITY_DIRECT]);
        $other = $this->fixturePost($this->remote());
        $remoteQuote = $this->fixturePost($this->remote(), attributes: ['quoted_post_id' => $other->id]);
        $this->assertSame([], $this->ids(true));
        $this->assertEqualsCanonicalizing([$other->id, $remoteQuote->id], $this->ids(false));
        $this->assertSame(4, Post::query()->count());
    }

    public function test_visibility_requires_the_same_viewer_for_home_source_and_private_access(): void
    {
        $this->enable();
        $sourceViewer = $this->createFullAccount('retentionsource')->actor;
        $member = $this->createFullAccount('retentionmember');
        $group = $this->remote(Actor::TYPE_GROUP);
        $community = Community::query()->create(['actor_id' => $group->id, 'owner_user_id' => $member->id, 'slug' => 'retention-private', 'is_private' => true]);
        $author = $this->remote();
        $this->follow($sourceViewer, $author);
        $this->follow($member->actor, $group);
        $post = $this->fixturePost($author, attributes: ['community_id' => $community->id, 'visibility' => Post::VISIBILITY_FOLLOWERS]);
        $this->assertSame([], $this->ids(true));
        $this->assertSame([$post->id], $this->ids(false));
        $this->follow($member->actor, $author);
        $this->assertSame([$post->id], $this->ids(true));
        $this->assertSame([], $this->ids(false));
        $this->assertSame([$post->id], app(FeedQuery::class)->forActor($member->actor)->getCollection()->pluck('id')->all());
    }

    public function test_public_community_membership_is_a_home_source_without_following_the_author(): void
    {
        $this->enable();
        $viewer = $this->createFullAccount('retentioncommunity');
        $group = $this->remote(Actor::TYPE_GROUP);
        $community = Community::query()->create(['actor_id' => $group->id, 'owner_user_id' => $viewer->id, 'slug' => 'retention-public']);
        $this->follow($viewer->actor, $group);
        $post = $this->fixturePost($this->remote(), attributes: ['community_id' => $community->id]);
        $this->assertSame([$post->id], $this->ids(true));
        $this->assertSame([$post->id], app(FeedQuery::class)->forActor($viewer->actor)->getCollection()->pluck('id')->all());
    }

    public function test_invalid_batch_limit_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(RemotePostRetentionQuery::class)->nonPertinent(0);
    }

    public function test_hashtags_and_boosts_do_not_bypass_post_visibility(): void
    {
        $this->enable();
        $viewer = $this->createFullAccount('retentionvisibility')->actor;
        $tag = (string) Str::uuid();
        DB::table('hashtags')->insert(['id' => $tag, 'name' => 'visibility']);
        DB::table('hashtag_follows')->insert(['actor_id' => $viewer->id, 'hashtag_id' => $tag]);
        $unlisted = $this->fixturePost($this->remote(), attributes: ['visibility' => Post::VISIBILITY_UNLISTED]);
        DB::table('post_hashtags')->insert(['post_id' => $unlisted->id, 'hashtag_id' => $tag]);
        $followers = $this->fixturePost($this->remote(), attributes: ['visibility' => Post::VISIBILITY_FOLLOWERS]);
        Announce::query()->create(['actor_id' => $viewer->id, 'post_id' => $followers->id, 'is_direct' => true]);
        $this->assertSame([], $this->ids(true));
        $this->assertEqualsCanonicalizing([$unlisted->id, $followers->id], $this->ids(false));
        $this->assertSame([], app(FeedQuery::class)->forActor($viewer)->getCollection()->pluck('id')->all());
    }
}
