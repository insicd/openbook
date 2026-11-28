<?php

namespace Tests\Feature\Rest;

use App\Application\Services\CommunityRegistrar;
use App\Domain\Messaging\Conversation;
use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\UsesPassportKeys;
use Tests\TestCase;

class HashtagPostTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase, UsesPassportKeys;

    private Actor $viewer;

    private Hashtag $hashtag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        $this->configurePassportKeys();
        $user = $this->createFullAccount('tagpostsviewer');
        $this->viewer = $user->actor;
        $this->hashtag = Hashtag::create(['name' => 'fotografia']);
        Passport::actingAs($user, ['api'], 'rest');
        $this->withToken('test-token');
        Http::preventStrayRequests();
    }

    public function test_it_returns_only_associated_posts_in_the_existing_rest_representation(): void
    {
        $remote = Actor::create([
            'type' => Actor::TYPE_PERSON, 'is_local' => false, 'status' => Actor::STATUS_ACTIVE,
            'preferred_username' => 'remote', 'domain' => 'social.example', 'uri' => 'https://social.example/users/remote',
        ]);
        $localPost = $this->taggedPost($this->viewer);
        $remotePost = $this->taggedPost($remote, [
            'uri' => 'https://social.example/users/remote/statuses/12', 'published_at' => now(),
        ]);
        Post::create([
            'actor_id' => $this->viewer->id, 'body' => 'Text mentions #fotografia',
            'status' => Post::STATUS_PUBLISHED, 'visibility' => Post::VISIBILITY_PUBLIC, 'published_at' => now(),
        ]);

        $this->getJson('/rest/v1/hashtags/fotografia/posts')->assertOk()->assertJsonCount(2)
            ->assertJsonPath('0.postId', $remotePost->id)
            ->assertJsonPath('0.postUri', $remotePost->uri)
            ->assertJsonPath('1.postId', $localPost->id)
            ->assertJsonPath('1.postAuthor.personId', $this->viewer->id)
            ->assertJsonMissingPath('0.postTimelineAt')
            ->assertHeaderMissing('Link');
        Http::assertNothingSent();
    }

    public function test_the_next_link_uses_the_canonical_tag_and_orders_ties_by_id(): void
    {
        $first = $this->taggedPost($this->viewer);
        $second = $this->taggedPost($this->viewer);
        $ids = [$first->id, $second->id];
        rsort($ids, SORT_STRING);

        $response = $this->getJson('/rest/v1/hashtags/%23Fotografia/posts?limit=1')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.postId', $ids[0]);
        $query = http_build_query(['limit' => 1, 'sort' => 'postPublishedAt', 'direction' => 'desc', 'after' => $ids[0]]);
        $next = route('rest.v1.hashtags.posts', ['tag' => 'fotografia']).'?'.$query;
        $response->assertHeader('Link', '<'.$next.'>; rel="next"');

        $this->taggedPost($this->viewer, ['published_at' => now()]);
        $this->getJson($next)->assertOk()->assertJsonCount(1)->assertJsonPath('0.postId', $ids[1])
            ->assertHeaderMissing('Link');
    }

    public function test_visibility_and_collection_membership_apply_to_results_and_anchors(): void
    {
        $other = $this->createFullAccount('poststagsauthor');
        $private = app(CommunityRegistrar::class)->register($other, [
            'slug' => 'tagprivate', 'name' => 'Private community', 'is_private' => true,
        ]);
        $conversation = Conversation::create([
            'participant_low_id' => min($this->viewer->id, $other->actor->id),
            'participant_high_id' => max($this->viewer->id, $other->actor->id),
        ]);
        $restricted = $this->taggedPost($other->actor, ['visibility' => Post::VISIBILITY_FOLLOWERS]);
        $privatePost = $this->taggedPost($other->actor, ['community_id' => $private->id]);
        $message = $this->taggedPost($this->viewer, [
            'visibility' => Post::VISIBILITY_DIRECT, 'conversation_id' => $conversation->id,
        ]);
        $deleted = $this->taggedPost($other->actor, ['status' => Post::STATUS_DELETED]);
        $unrelated = $this->taggedPost($other->actor);
        $unrelated->hashtags()->detach($this->hashtag->id);
        $visible = $this->taggedPost($other->actor, ['visibility' => Post::VISIBILITY_UNLISTED]);
        $url = '/rest/v1/hashtags/fotografia/posts';
        $this->getJson($url)->assertOk()->assertJsonCount(1)->assertJsonPath('0.postId', $visible->id);

        foreach ([$restricted, $privatePost, $message, $deleted, $unrelated] as $post) {
            $this->getJson($url.'?after='.$post->id)->assertUnprocessable()->assertJsonStructure(['errors' => ['after']]);
        }

        Follow::create([
            'follower_id' => $this->viewer->id, 'following_id' => $other->actor->id,
            'status' => Follow::STATUS_ACCEPTED, 'requested_at' => now(), 'accepted_at' => now(),
        ]);
        $response = $this->getJson($url)->assertOk()->assertJsonCount(2);
        $this->assertEqualsCanonicalizing([$visible->id, $restricted->id], $response->json('*.postId'));
    }

    public function test_an_empty_tag_and_unknown_or_invalid_tags_have_distinct_responses(): void
    {
        $this->getJson('/rest/v1/hashtags/fotografia/posts')->assertOk()->assertExactJson([]);
        foreach (['inesistente', 'non-valido'] as $tag) {
            $this->getJson('/rest/v1/hashtags/'.$tag.'/posts')->assertNotFound()
                ->assertHeader('Content-Type', 'application/problem+json');
        }
    }

    public function test_rest_posts_keep_hashtag_content_warnings_without_loading_web_poll_data(): void
    {
        config(['openbook.moderation.forced_content_warning_hashtags' => ['fotografia']]);
        $this->taggedPost($this->viewer);

        DB::enableQueryLog();
        try {
            $response = $this->getJson('/rest/v1/hashtags/fotografia/posts')->assertOk();
            $queries = array_column(DB::getQueryLog(), 'query');
        } finally {
            DB::disableQueryLog();
        }

        $response->assertJsonPath('0.postCW', true)
            ->assertJsonPath('0.postCWText', __('openbook.posts.forced_content_warning'));
        $this->assertStringNotContainsString('from "polls"', implode("\n", $queries));
    }

    public function test_conditional_get_checks_membership_and_authentication(): void
    {
        $post = $this->taggedPost($this->viewer);
        $url = '/rest/v1/hashtags/fotografia/posts';
        $etag = $this->getJson($url)->assertOk()->assertHeader('Vary', 'Authorization')->headers->get('ETag');
        $this->assertNotNull($etag);
        $this->withHeader('If-None-Match', $etag)->getJson($url)->assertNotModified();

        $post->hashtags()->detach($this->hashtag->id);
        $this->getJson($url)->assertOk()->assertExactJson([]);
        $this->withoutHeader('Authorization')->getJson($url)->assertUnauthorized();
    }

    private function taggedPost(Actor $author, array $attributes = []): Post
    {
        $post = Post::create(array_replace([
            'actor_id' => $author->id, 'body' => 'Tagged post', 'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC, 'published_at' => now()->subHour(),
        ], $attributes));
        $post->hashtags()->attach($this->hashtag->id);

        return $post;
    }
}
