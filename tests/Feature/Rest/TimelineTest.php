<?php

namespace Tests\Feature\Rest;

use App\Application\Queries\FeedQuery;
use App\Application\Services\CommunityRegistrar;
use App\Domain\Accounts\User;
use App\Domain\Posts\Post;
use App\Domain\Reactions\Announce;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\UsesPassportKeys;
use Tests\TestCase;

class TimelineTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase, UsesPassportKeys;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->configurePassportKeys();
        $this->viewer = $this->createFullAccount('timelineviewer');
        Passport::actingAs($this->viewer, ['api'], 'rest');
        $this->withToken('test-token');
        Http::preventStrayRequests();
    }

    private function follow(Actor $actor): void
    {
        Follow::create(['follower_id' => $this->viewer->actor->id, 'following_id' => $actor->id,
            'status' => 'accepted', 'requested_at' => now(), 'accepted_at' => now()]);
    }

    private function published(Actor $author, int $minutesAgo, array $attributes = []): Post
    {
        return Post::create(array_merge(['actor_id' => $author->id, 'body' => 'Timeline source',
            'visibility' => 'public', 'status' => 'published', 'published_at' => now()->subMinutes($minutesAgo)->startOfSecond()], $attributes));
    }

    private function announce(Actor $actor, Post $post, int $minutesAgo, bool $direct = true): Announce
    {
        $announce = Announce::create(['actor_id' => $actor->id, 'post_id' => $post->id, 'is_direct' => $direct]);
        $announce->forceFill(['created_at' => now()->subMinutes($minutesAgo)->startOfSecond()])->save();

        return $announce;
    }

    private function next($response): string
    {
        preg_match('/<([^>]+)>; rel="next"/', $response->headers->get('Link', ''), $match);
        $this->assertArrayHasKey(1, $match);

        return $match[1];
    }

    public function test_timeline_matches_the_core_and_preserves_original_author_and_relevant_announce_context(): void
    {
        $author = $this->createFullAccount('originalauthor')->actor;
        $sharer = $this->createFullAccount('followedsharer')->actor;
        $outsider = $this->createFullAccount('unfollowedsharer')->actor;
        $this->follow($sharer);
        $ordinary = $this->published($this->viewer->actor, 30);
        $boosted = $this->published($author, 1000);
        $share = $this->announce($sharer, $boosted, 10);
        $this->announce($outsider, $boosted, 1);
        $secret = $this->published($author, 2, ['visibility' => 'followers']);
        $this->announce($sharer, $secret, 1);

        $expected = app(FeedQuery::class)->forActor($this->viewer->actor, null, 20)->getCollection()->pluck('id')->all();
        $response = $this->get('/rest/v1/posts?engine=timeline')->assertOk()->assertJsonCount(2)
            ->assertJsonPath('0.postId', $boosted->id)
            ->assertJsonPath('0.postAuthor.personId', $author->id)
            ->assertJsonPath('0.postAnnounce.announceAuthor.personId', $sharer->id)
            ->assertJsonPath('0.postAnnounce.announcePublishedAt', $share->fresh()->created_at->toISOString())
            ->assertJsonPath('0.postPublishedAt', $boosted->fresh()->published_at->toISOString())
            ->assertJsonPath('1.postId', $ordinary->id)->assertJsonMissingPath('1.postAnnounce');
        $this->assertSame($expected, $response->json('*.postId'));
        $this->assertSame($response->json('0.postTimelineAt'), $response->json('0.postAnnounce.announcePublishedAt'));
        $this->assertSame($response->json('1.postTimelineAt'), $response->json('1.postPublishedAt'));
        $this->get('/rest/v1/posts')->assertOk()->assertExactJson([]);
        Http::assertNothingSent();
    }

    public function test_timeline_links_preserve_date_and_id_for_ties_and_do_not_duplicate_posts(): void
    {
        $author = $this->createFullAccount('paginationauthor')->actor;
        $firstSharer = $this->createFullAccount('firstsharer')->actor;
        $secondSharer = $this->createFullAccount('secondsharer')->actor;
        $this->follow($firstSharer);
        $this->follow($secondSharer);
        foreach (range(1, 3) as $i) {
            $post = $this->published($author, 1000 + $i);
            $this->announce($firstSharer, $post, 10);
            $this->announce($secondSharer, $post, 5);
        }
        $expected = app(FeedQuery::class)->forActor($this->viewer->actor, null, 20)->getCollection()->pluck('id')->all();
        $ids = [];
        $url = '/rest/v1/posts?engine=timeline&limit=1';
        for ($page = 0; $page < 3; $page++) {
            $response = $this->get($url)->assertOk()->assertJsonCount(1)
                ->assertJsonPath('0.postAnnounce.announceAuthor.personId', $secondSharer->id);
            $ids[] = $response->json('0.postId');
            if ($page < 2) {
                $url = $this->next($response);
                parse_str(parse_url($url, PHP_URL_QUERY), $query);
                $this->assertSame($response->json('0.postTimelineAt'), $query['afterAt']);
                $this->assertSame($response->json('0.postId'), $query['after']);
                $this->assertArrayNotHasKey('cursor', $query);
                $this->assertSame('postTimelineAt', $query['sort']);
            } else {
                $response->assertHeaderMissing('Link');
            }
        }
        $this->assertSame($expected, $ids);
    }

    public function test_a_new_share_of_the_reference_post_does_not_move_the_saved_page_boundary(): void
    {
        $author = $this->createFullAccount('oldauthor')->actor;
        $sharer = $this->createFullAccount('newsharer')->actor;
        $this->follow($sharer);
        $recent = $this->published($this->viewer->actor, 10);
        $boosted = $this->published($author, 1000);
        $share = $this->announce($sharer, $boosted, 20);
        $remaining = $this->published($this->viewer->actor, 30);
        $first = $this->get('/rest/v1/posts?engine=timeline&limit=2')->assertOk()
            ->assertJsonPath('0.postId', $recent->id)->assertJsonPath('1.postId', $boosted->id);
        $next = $this->next($first);
        parse_str(parse_url($next, PHP_URL_QUERY), $parameters);
        $parameters['afterAt'] = $share->fresh()->created_at->copy()->setTimezone('Europe/Rome')->toIso8601String();
        $this->get('/rest/v1/posts?'.http_build_query($parameters))->assertOk()
            ->assertJsonCount(1)->assertJsonPath('0.postId', $remaining->id);
        $share->forceFill(['created_at' => now()->subMinute()->startOfSecond()])->save();
        $this->get($next)->assertOk()->assertJsonCount(1)->assertJsonPath('0.postId', $remaining->id);
        $boosted->delete();
        $this->get($next)->assertOk()->assertJsonCount(1)->assertJsonPath('0.postId', $remaining->id);
    }

    public function test_group_distribution_is_an_announce_but_a_person_quote_does_not_boost_the_original(): void
    {
        $author = $this->createFullAccount('groupcontentauthor')->actor;
        $quoter = $this->createFullAccount('quoter')->actor;
        $community = app(CommunityRegistrar::class)->register($this->viewer, ['slug' => 'timelinespace', 'name' => 'Timeline space']);
        $this->follow($quoter);
        $groupPost = $this->published($author, 1000, ['community_id' => $community->id]);
        $this->announce($community->actor, $groupPost, 5, false);
        $original = $this->published($author, 2000);
        $quote = $this->published($quoter, 10, ['quoted_post_id' => $original->id]);
        $this->announce($quoter, $original, 10, false);
        $this->get('/rest/v1/posts?engine=timeline')->assertOk()->assertJsonCount(2)
            ->assertJsonPath('0.postId', $groupPost->id)
            ->assertJsonPath('0.postAnnounce.announceAuthor.personId', $community->actor->id)
            ->assertJsonPath('1.postId', $quote->id)->assertJsonMissingPath('1.postAnnounce');
    }

    public function test_self_shares_keep_the_ordering_context_even_when_the_ui_hides_the_redundant_banner(): void
    {
        $post = $this->published($this->viewer->actor, 1000);
        $share = $this->announce($this->viewer->actor, $post, 5);
        $this->get('/rest/v1/posts?engine=timeline')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.postAnnounce.announceAuthor.personId', $post->actor_id)
            ->assertJsonPath('0.postTimelineAt', $share->fresh()->created_at->toISOString());
    }

    public function test_timeline_validation_private_cache_and_authentication(): void
    {
        $post = $this->published($this->viewer->actor, 10);
        foreach ([
            'after='.$post->id,
            'afterAt=2026-01-01T00:00:00Z',
            'after='.$post->id.'&afterAt=bad',
            'after='.$post->id.'&afterAt=2026-02-31T00:00:00Z',
            'sort=postPublishedAt', 'direction=asc', 'cursor=old',
        ] as $query) {
            $this->get('/rest/v1/posts?engine=timeline&'.$query)->assertStatus(422);
        }
        $first = $this->get('/rest/v1/posts?engine=timeline')->assertOk()->assertHeader('Vary', 'Authorization');
        $this->withHeader('If-None-Match', $first->headers->get('ETag'))->get('/rest/v1/posts?engine=timeline')->assertStatus(304);
        $this->withoutHeader('Authorization')->get('/rest/v1/posts?engine=timeline')->assertUnauthorized();
    }
}
