<?php

namespace Tests\Feature\Rest;

use App\Application\Queries\FeedQuery;
use App\Application\Services\CommunityRegistrar;
use App\Domain\Accounts\User;
use App\Domain\Feeds\FeedSource;
use App\Domain\Posts\Post;
use App\Domain\Reactions\Announce;
use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\Concerns\UsesPassportKeys;
use Tests\TestCase;

class PersonPostTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase, UsesPassportKeys;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        $this->configurePassportKeys();
        $this->viewer = $this->createFullAccount('personpostsviewer');
        Passport::actingAs($this->viewer, ['api'], 'rest');
        $this->withToken('test-token');
        Http::preventStrayRequests();
    }

    private function published(Actor $actor, int $minutesAgo, array $attributes = []): Post
    {
        return Post::create(array_merge(['actor_id' => $actor->id, 'body' => 'Cached text',
            'uri' => $actor->isLocal() ? null : $actor->uri.'/posts/'.uniqid(),
            'visibility' => 'public', 'status' => 'published', 'published_at' => now()->subMinutes($minutesAgo)], $attributes));
    }

    private function announce(Actor $actor, Post $post, int $minutesAgo): void
    {
        $share = Announce::create(['actor_id' => $actor->id, 'post_id' => $post->id, 'is_direct' => true]);
        $share->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();
    }

    private function url(Actor $actor): string
    {
        return '/rest/v1/persons/'.$actor->id.'/posts';
    }

    private function next($response): string
    {
        preg_match('/<([^>]+)>; rel="next"/', $response->headers->get('Link', ''), $matches);
        $this->assertArrayHasKey(1, $matches);

        return $matches[1];
    }

    public function test_local_profile_matches_the_web_feed_with_own_posts_and_announces_and_visibility(): void
    {
        $owner = $this->createFullAccount('profilepostsowner')->actor;
        $author = $this->createFullAccount('originalprofileauthor')->actor;
        $own = $this->published($owner, 30);
        $boosted = $this->published($author, 1000);
        $this->announce($owner, $boosted, 10);
        $this->published($owner, 1, ['visibility' => 'followers']);
        $this->published($owner, 2, ['status' => 'deleted']);
        $this->published($author, 3);
        $expected = app(FeedQuery::class)->forProfile($owner, $this->viewer->actor)->getCollection()->pluck('id')->all();
        $response = $this->getJson($this->url($owner))->assertOk()->assertJsonCount(2)
            ->assertJsonPath('0.postId', $boosted->id)->assertJsonPath('0.postAuthor.personId', $author->id)
            ->assertJsonPath('0.postAnnounce.announceAuthor.personId', $owner->id)
            ->assertJsonPath('0.postTimelineAt', now()->subMinutes(10)->toISOString())
            ->assertJsonPath('1.postId', $own->id)->assertJsonMissingPath('1.postAnnounce');
        $this->assertSame($expected, $response->json('*.postId'));
        $response->assertHeader('Vary', 'Authorization');
        $this->withHeader('If-None-Match', $response->headers->get('ETag'))->getJson($this->url($owner))->assertStatus(304);
        Http::assertNothingSent();
    }

    public function test_pagination_uses_web_page_size_and_rest_date_id_links_without_duplicates(): void
    {
        config(['openbook.feed.per_page' => 1]);
        $owner = $this->createFullAccount('pageprofile')->actor;
        foreach (range(1, 3) as $i) {
            $this->published($owner, 10);
        }
        $original = $this->createFullAccount('pageoriginal')->actor;
        $boosted = $this->published($original, 1000);
        $this->announce($owner, $boosted, 10);
        $expected = Post::where('actor_id', $owner->id)->pluck('id')->push($boosted->id)->sortDesc()->values()->all();
        $ids = [];
        $url = $this->url($owner);
        for ($i = 0; $i < 4; $i++) {
            $response = $this->getJson($url)->assertOk()->assertJsonCount(1);
            $ids[] = $response->json('0.postId');
            if ($i < 3) {
                $url = $this->next($response);
                parse_str(parse_url($url, PHP_URL_QUERY), $query);
                $this->assertSame($response->json('0.postTimelineAt'), $query['afterAt']);
                $this->assertSame($response->json('0.postId'), $query['after']);
                $this->assertArrayNotHasKey('cursor', $query);
                $this->assertArrayNotHasKey('limit', $query);
            } else {
                $response->assertHeaderMissing('Link');
            }
        }
        $this->assertSame($expected, $ids);
        $this->assertSame(1, config('openbook.feed.per_page'));
    }

    public function test_groups_keep_publication_order_even_when_announce_dates_have_the_opposite_order(): void
    {
        config(['openbook.feed.per_page' => 1]);
        $community = app(CommunityRegistrar::class)->register($this->viewer, ['slug' => 'profilegroup', 'name' => 'Profile group']);
        $author = $this->createFullAccount('grouporiginal')->actor;
        $newer = $this->published($author, 20, ['community_id' => $community->id]);
        $older = $this->published($author, 40, ['community_id' => $community->id]);
        $this->announce($community->actor, $newer, 10);
        $this->announce($community->actor, $older, 1);
        $first = $this->getJson($this->url($community->actor))->assertOk()->assertJsonPath('0.postId', $newer->id)
            ->assertJsonPath('0.postAnnounce.announceAuthor.personId', $community->actor->id)
            ->assertJsonPath('0.postTimelineAt', $newer->published_at->toISOString());
        $this->getJson($this->next($first))->assertOk()->assertJsonPath('0.postId', $older->id)->assertHeaderMissing('Link');
    }

    public function test_remote_posts_are_imported_here_with_ttl_but_not_by_person_details(): void
    {
        $owner = $this->createRemoteActor('newpostsprofile', overrides: ['collections_fetched_at' => now()]);
        $outbox = $owner->endpoints->outbox;
        $activity = fn (string $suffix, int $minutesAgo): array => ['id' => $owner->uri.'/activities/'.$suffix,
            'type' => 'Create', 'actor' => $owner->uri, 'object' => ['id' => $owner->uri.'/posts/'.$suffix,
                'type' => 'Note', 'attributedTo' => $owner->uri, 'content' => '<p>Remote '.$suffix.'</p>',
                'published' => now()->subMinutes($minutesAgo)->toISOString(), 'to' => ['https://www.w3.org/ns/activitystreams#Public']]];
        Http::fake([
            $outbox => Http::response(['type' => 'OrderedCollection', 'first' => $outbox.'?page=1']),
            $outbox.'?page=1' => Http::sequence()
                ->push(['type' => 'OrderedCollectionPage', 'orderedItems' => [$activity('first', 30)]])
                ->push(['type' => 'OrderedCollectionPage', 'orderedItems' => [$activity('second', 10)]]),
        ]);
        $this->getJson('/rest/v1/persons/'.$owner->id)->assertOk();
        Http::assertNothingSent();
        $first = $this->getJson($this->url($owner))->assertOk()->assertJsonCount(1)->assertJsonPath('0.postContent', 'Remote first');
        $this->withHeader('If-None-Match', $first->headers->get('ETag'))->getJson($this->url($owner))->assertStatus(304);
        Http::assertSentCount(2);
        $owner->refresh()->update(['posts_fetched_at' => now()->subHours(7), 'events_fetched_at' => now()->subHours(7)]);
        $this->getJson($this->url($owner))->assertOk()->assertJsonCount(2)->assertJsonPath('0.postContent', 'Remote second');
        Http::assertSentCount(4);
    }

    public function test_remote_failures_preserve_cached_posts_and_disappearance_is_rechecked(): void
    {
        $owner = $this->createRemoteActor('failingprofile');
        $post = $this->published($owner, 10);
        $deleteOnFetch = false;
        Http::fake(function () use ($owner, &$deleteOnFetch) {
            if ($deleteOnFetch) {
                $owner->update(['status' => Actor::STATUS_DELETED]);
            }

            return Http::response('', 503);
        });
        $this->getJson($this->url($owner))->assertOk()->assertJsonPath('0.postId', $post->id);
        $owner->refresh()->update(['posts_fetched_at' => now()->subHours(7), 'events_fetched_at' => now()->subHours(7)]);
        $deleteOnFetch = true;
        $this->withHeader('If-None-Match', '"old"')->getJson($this->url($owner))->assertNotFound();
    }

    public function test_private_group_authorization_is_checked_before_conditional_responses(): void
    {
        $community = app(CommunityRegistrar::class)->register($this->viewer, ['slug' => 'privateprofilegroup', 'name' => 'Private', 'is_private' => true]);
        $first = $this->getJson($this->url($community->actor))->assertOk();
        Passport::actingAs($this->createFullAccount('outsideprofilegroup'), ['api'], 'rest');
        $this->withHeader('If-None-Match', $first->headers->get('ETag'))->getJson($this->url($community->actor))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_rss_uses_existing_importer_and_feed_refresh_interval(): void
    {
        $owner = Actor::create(['type' => 'feed', 'is_local' => true, 'preferred_username' => 'newsfeed',
            'domain' => config('openbook.domain'), 'uri' => 'https://rss.example/actor', 'name' => 'News', 'status' => 'active']);
        $source = FeedSource::create(['actor_id' => $owner->id, 'feed_url' => 'https://rss.example/news.xml',
            'feed_url_hash' => FeedSource::hashUrl('https://rss.example/news.xml'), 'format' => 'rss']);
        $xml = '<rss version="2.0"><channel><title>News</title><link>https://rss.example</link><description>News</description>'
            .'<item><guid>https://rss.example/first</guid><link>https://rss.example/first</link><title>First story</title>'
            .'<description>Feed text</description><pubDate>Mon, 26 Oct 2026 12:00:00 GMT</pubDate></item></channel></rss>';
        Http::fake([$source->feed_url => Http::sequence()->push($xml, 200, ['Content-Type' => 'application/rss+xml', 'ETag' => '"feed-v1"'])->push('', 304)]);
        $this->getJson($this->url($owner))->assertOk()->assertJsonCount(1)->assertJsonPath('0.postName', 'First story');
        $this->getJson($this->url($owner))->assertOk()->assertJsonCount(1);
        Http::assertSentCount(1);
        $source->refresh()->update(['last_fetched_at' => now()->subMinutes(31)]);
        $this->getJson($this->url($owner))->assertOk()->assertJsonCount(1);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->hasHeader('If-None-Match', '"feed-v1"'));
    }

    public function test_validation_authentication_and_unavailable_profiles(): void
    {
        $url = $this->url($this->viewer->actor);
        foreach (['limit=1', 'cursor=old', 'page=2', 'engine=timeline', 'sort=postPublishedAt', 'direction=asc',
            'after='.$this->viewer->actor->id, 'afterAt=2026-01-01T00:00:00Z',
            'after='.$this->viewer->actor->id.'&afterAt=bad'] as $query) {
            $this->getJson($url.'?'.$query)->assertStatus(422);
        }
        $this->getJson('/rest/v1/persons/not-a-uuid/posts')->assertNotFound();
        $this->withoutHeader('Authorization')->getJson($url)->assertUnauthorized();
    }
}
