<?php

namespace Tests\Feature\Rest;

use App\Application\Queries\FeedQuery;
use App\Application\Services\CommunityRegistrar;
use App\Domain\Accounts\User;
use App\Domain\Messaging\Conversation;
use App\Domain\Posts\Post;
use App\Domain\Reactions\Announce;
use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\UsesPassportKeys;
use Tests\TestCase;

class MePostTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase, UsesPassportKeys;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        $this->configurePassportKeys();
        $this->viewer = $this->createFullAccount('mypostsviewer');
        Passport::actingAs($this->viewer, ['api'], 'rest');
        $this->withToken('test-token');
        Http::preventStrayRequests();
    }

    private function published(Actor $author, array $attributes = []): Post
    {
        return Post::create(array_merge(['actor_id' => $author->id, 'body' => 'My text',
            'status' => Post::STATUS_PUBLISHED, 'visibility' => 'public', 'published_at' => now()->subHour()], $attributes));
    }

    private function share(Actor $actor, Post $post): void
    {
        Announce::create(['actor_id' => $actor->id, 'post_id' => $post->id, 'is_direct' => true]);
    }

    private function next($response): string
    {
        preg_match('/<([^>]+)>; rel="next"/', $response->headers->get('Link', ''), $matches);
        $this->assertArrayHasKey(1, $matches);

        return $matches[1];
    }

    public function test_only_owned_published_posts_are_returned_without_announce_context(): void
    {
        $other = $this->createFullAccount('mypostsother')->actor;
        $owned = $this->published($this->viewer->actor);
        $original = $this->published($other);
        $this->share($this->viewer->actor, $original);
        $this->share($this->viewer->actor, $owned);
        $this->share($other, $owned);
        $this->published($this->viewer->actor, ['status' => Post::STATUS_DELETED]);
        $conversation = Conversation::create(['participant_low_id' => min($this->viewer->actor->id, $other->id),
            'participant_high_id' => max($this->viewer->actor->id, $other->id)]);
        $this->published($this->viewer->actor, ['visibility' => 'direct', 'conversation_id' => $conversation->id]);
        $this->getJson('/rest/v1/me/posts')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.postId', $owned->id)->assertJsonPath('0.postAuthor.personId', $this->viewer->actor->id)
            ->assertJsonPath('0.postMyInteractions.postDirectAnnounced', true)
            ->assertJsonMissingPath('0.postAnnounce')->assertJsonMissingPath('0.postTimelineAt')->assertJsonMissingPath('data');
        Http::assertNothingSent();
    }

    public function test_own_restricted_community_and_quote_posts_are_included(): void
    {
        $community = app(CommunityRegistrar::class)->register($this->viewer,
            ['slug' => 'myprivateposts', 'name' => 'Private', 'is_private' => true]);
        $original = $this->published($this->createFullAccount('myquoteoriginal')->actor);
        $ids = [];
        foreach ([['visibility' => 'followers'], ['visibility' => 'direct'],
            ['community_id' => $community->id], ['quoted_post_id' => $original->id]] as $attributes) {
            $ids[] = $this->published($this->viewer->actor, $attributes)->id;
        }
        $response = $this->getJson('/rest/v1/me/posts')->assertOk()->assertJsonCount(4);
        $this->assertEqualsCanonicalizing($ids, $response->json('*.postId'));
    }

    public function test_pagination_reuses_publication_order_and_keeps_equal_dates_and_new_posts_stable(): void
    {
        $posts = collect(range(1, 4))->map(fn () => $this->published($this->viewer->actor));
        $expected = app(FeedQuery::class)->paginatePublishedQuery($this->viewer->actor->posts(), null, 100)
            ->getCollection()->pluck('id')->all();
        $ids = [];
        $url = '/rest/v1/me/posts?limit=1';
        for ($i = 0; $i < 4; $i++) {
            $response = $this->getJson($url)->assertOk()->assertJsonCount(1);
            $ids[] = $response->json('0.postId');
            if ($i === 0) {
                $this->published($this->viewer->actor, ['published_at' => now()]);
                $this->share($this->viewer->actor, $posts->first());
            }
            if ($i < 3) {
                $url = $this->next($response);
                parse_str(parse_url($url, PHP_URL_QUERY), $query);
                $this->assertSame($response->json('0.postId'), $query['after']);
                $this->assertSame('postPublishedAt', $query['sort']);
                $this->assertSame('desc', $query['direction']);
                $this->assertArrayNotHasKey('afterAt', $query);
                $this->assertArrayNotHasKey('cursor', $query);
            } else {
                $response->assertHeaderMissing('Link');
            }
        }
        $this->assertSame($expected, $ids);
    }

    public function test_an_own_announce_does_not_change_publication_order(): void
    {
        $older = $this->published($this->viewer->actor, ['published_at' => now()->subDays(2)]);
        $newer = $this->published($this->viewer->actor, ['published_at' => now()->subDay()]);
        $this->share($this->viewer->actor, $older);
        $this->getJson('/rest/v1/me/posts')->assertOk()->assertJsonCount(2)
            ->assertJsonPath('0.postId', $newer->id)->assertJsonPath('1.postId', $older->id)
            ->assertJsonMissingPath('1.postAnnounce')->assertJsonMissingPath('1.postTimelineAt');
    }

    public function test_default_limit_and_empty_collection(): void
    {
        $this->getJson('/rest/v1/me/posts')->assertOk()->assertExactJson([])->assertHeaderMissing('Link');
        foreach (range(1, 21) as $i) {
            $this->published($this->viewer->actor);
        }
        $first = $this->getJson('/rest/v1/me/posts')->assertOk()->assertJsonCount(20);
        $this->getJson($this->next($first))->assertOk()->assertJsonCount(1)->assertHeaderMissing('Link');
        $this->getJson('/rest/v1/me/posts?limit=100')->assertOk()->assertJsonCount(21)->assertHeaderMissing('Link');
    }

    public function test_anchor_must_belong_to_the_same_available_collection(): void
    {
        $other = $this->published($this->createFullAccount('myforeignanchor')->actor);
        $this->share($this->viewer->actor, $other);
        $deleted = $this->published($this->viewer->actor, ['status' => Post::STATUS_DELETED]);
        foreach ([$other->id, $deleted->id, (string) Str::uuid()] as $id) {
            $this->getJson('/rest/v1/me/posts?after='.$id)->assertUnprocessable()->assertJsonStructure(['errors' => ['after']]);
        }
    }

    public function test_invalid_and_unsupported_parameters_are_rejected(): void
    {
        foreach (['after=invalid', 'limit=0', 'limit=101', 'limit=abc', 'sort=postTimelineAt', 'direction=asc',
            'afterAt=2026-01-01T00:00:00Z', 'engine=timeline', 'page=1', 'cursor=opaque'] as $query) {
            $this->getJson('/rest/v1/me/posts?'.$query)->assertUnprocessable()->assertHeader('Content-Type', 'application/problem+json');
        }
    }

    public function test_private_cache_tracks_changes_and_isolated_bearer_identities(): void
    {
        $post = $this->published($this->viewer->actor);
        $first = $this->getJson('/rest/v1/me/posts')->assertOk()->assertHeader('Vary', 'Authorization');
        $this->assertTrue($first->headers->hasCacheControlDirective('private'));
        $etag = $first->headers->get('ETag');
        $this->withHeader('If-None-Match', $etag)->getJson('/rest/v1/me/posts')->assertStatus(304);
        $post->update(['body' => 'Changed']);
        $this->getJson('/rest/v1/me/posts')->assertOk()->assertJsonPath('0.postContent', 'Changed');
        $other = $this->createFullAccount('mycacheother');
        $ownOther = $this->published($other->actor);
        Passport::actingAs($other, ['api'], 'rest');
        $this->getJson('/rest/v1/me/posts')->assertOk()->assertJsonCount(1)->assertJsonPath('0.postId', $ownOther->id);
        $this->withoutHeader('Authorization')->getJson('/rest/v1/me/posts')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_scope_and_active_account_are_checked_before_conditional_responses(): void
    {
        $etag = $this->getJson('/rest/v1/me/posts')->assertOk()->headers->get('ETag');
        $this->withHeader('If-None-Match', $etag);
        Passport::actingAs($this->viewer, [], 'rest');
        $this->getJson('/rest/v1/me/posts')->assertForbidden();
        Passport::actingAs($this->viewer, ['api'], 'rest');
        $this->viewer->actor->update(['status' => Actor::STATUS_SUSPENDED]);
        $this->getJson('/rest/v1/me/posts')->assertForbidden();
    }
}
