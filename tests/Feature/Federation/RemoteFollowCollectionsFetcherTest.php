<?php

namespace Tests\Feature\Federation;

use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\SocialGraph\RemoteCollectionMember;
use App\Federation\SocialGraph\RemoteFollowCollectionsFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class RemoteFollowCollectionsFetcherTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_it_caches_collection_counts_and_first_page_members_without_creating_follows(): void
    {
        $viewer = $this->createFullAccount('colviewer');
        $remote = $this->createRemoteActor('coltarget');
        $alreadyKnown = $this->createRemoteActor('giaqui', 'altro.example');

        $followersUrl = $remote->endpoints->followers;
        $followingUrl = $remote->endpoints->following;
        $unknownUri = 'https://altro.example/users/sconosciuto';

        Http::fake([
            $followersUrl => Http::response([
                'id' => $followersUrl,
                'type' => 'OrderedCollection',
                'totalItems' => 12800,
                'orderedItems' => [$alreadyKnown->uri, $unknownUri],
            ], 200, ['Content-Type' => 'application/activity+json']),
            $followingUrl => Http::response([
                'id' => $followingUrl,
                'type' => 'OrderedCollection',
                'totalItems' => 3,
                'first' => $followingUrl.'?page=1',
            ], 200, ['Content-Type' => 'application/activity+json']),
            $followingUrl.'?page=1' => Http::response([
                'id' => $followingUrl.'?page=1',
                'type' => 'OrderedCollectionPage',
                'partOf' => $followingUrl,
                'orderedItems' => [$viewer->actor->uri],
            ], 200, ['Content-Type' => 'application/activity+json']),
            $remote->uri => Http::response($this->actorDocument($remote, [
                'published' => '2018-04-01T00:00:00Z',
            ]), 200, ['Content-Type' => 'application/activity+json']),
        ]);

        app(RemoteFollowCollectionsFetcher::class)->refreshIfStale($remote);

        $remote = $remote->fresh();
        $this->assertSame(12800, $remote->followers_count);
        $this->assertSame(3, $remote->following_count);
        $this->assertNotNull($remote->collections_fetched_at);

        $this->assertSame(0, Follow::query()->where('following_id', $remote->id)->count());
        $this->assertSame(0, Follow::query()->where('follower_id', $remote->id)->count());

        $followers = RemoteCollectionMember::query()
            ->where('actor_id', $remote->id)
            ->where('collection', RemoteCollectionMember::COLLECTION_FOLLOWERS)
            ->orderBy('position')
            ->get();

        $this->assertCount(2, $followers);
        $this->assertSame($alreadyKnown->id, $followers[0]->member_actor_id);
        $this->assertNull($followers[1]->member_actor_id);
        $this->assertSame($unknownUri, $followers[1]->member_uri);
    }

    public function test_it_does_not_refetch_within_the_ttl(): void
    {
        $remote = $this->createRemoteActor('colcache');
        Http::fake([
            $remote->endpoints->followers => Http::response([
                'id' => $remote->endpoints->followers,
                'type' => 'OrderedCollection',
                'totalItems' => 9,
                'orderedItems' => [],
            ], 200, ['Content-Type' => 'application/activity+json']),
            $remote->endpoints->following => Http::response([
                'id' => $remote->endpoints->following,
                'type' => 'OrderedCollection',
                'totalItems' => 1,
                'orderedItems' => [],
            ], 200, ['Content-Type' => 'application/activity+json']),
            $remote->uri => Http::response($this->actorDocument($remote, [
                'published' => '2018-04-01T00:00:00Z',
            ]), 200, ['Content-Type' => 'application/activity+json']),
        ]);

        $fetcher = app(RemoteFollowCollectionsFetcher::class);
        $fetcher->refreshIfStale($remote);
        Http::assertSentCount(2);

        $fetcher->refreshIfStale($remote->fresh());
        Http::assertSentCount(2);
    }

    public function test_the_followers_list_shows_cached_remote_members(): void
    {
        $viewer = $this->createFullAccount('colistviewer');
        $remote = $this->createRemoteActor('colistowner', overrides: [
            'followers_count' => 50,
            'collections_fetched_at' => now(),
        ]);
        $member = $this->createRemoteActor('colistmember', 'altro.example');

        RemoteCollectionMember::query()->create([
            'actor_id' => $remote->id,
            'collection' => RemoteCollectionMember::COLLECTION_FOLLOWERS,
            'member_uri' => $member->uri,
            'member_actor_id' => $member->id,
            'position' => 0,
        ]);

        Http::fake(['*' => Http::response('', 404)]);

        $response = $this->actingAs($viewer)->get(route('actors.followers', $remote));

        $response->assertOk();
        $response->assertSee('colistmember');
        $response->assertSee(__('openbook.follows.remote_preview_incomplete'));
        $this->assertSame(0, Follow::query()->count());
    }

    public function test_it_skips_local_and_feed_actors(): void
    {
        Http::fake();
        $local = $this->createFullAccount('collocal');

        app(RemoteFollowCollectionsFetcher::class)->refreshIfStale($local->actor);

        Http::assertNothingSent();
        $this->assertNull($local->actor->fresh()->collections_fetched_at);
    }

    public function test_it_refetches_the_actor_document_when_the_join_date_is_missing_from_cache(): void
    {
        $remote = $this->createRemoteActor('joindate', overrides: [
            'published_at' => null,
            'last_fetched_at' => now()->subDay(),
            'collections_fetched_at' => now(),
        ]);

        Http::fake([
            $remote->uri => Http::response($this->actorDocument($remote, [
                'published' => '2016-03-16T00:00:00Z',
            ]), 200, ['Content-Type' => 'application/activity+json']),
        ]);

        app(RemoteFollowCollectionsFetcher::class)->refreshIfStale($remote);

        $this->assertSame('2016-03-16 00:00:00', $remote->fresh()->published_at?->utc()->format('Y-m-d H:i:s'));
        Http::assertSentCount(1);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function actorDocument(Actor $remote, array $overrides = []): array
    {
        $remote->loadMissing(['key', 'endpoints']);

        return array_merge([
            'id' => $remote->uri,
            'type' => 'Person',
            'preferredUsername' => $remote->preferred_username,
            'name' => $remote->name,
            'inbox' => $remote->endpoints?->inbox,
            'outbox' => $remote->endpoints?->outbox,
            'followers' => $remote->endpoints?->followers,
            'following' => $remote->endpoints?->following,
            'publicKey' => [
                'id' => $remote->uri.'#main-key',
                'owner' => $remote->uri,
                'publicKeyPem' => $remote->key?->public_key ?? '-----BEGIN PUBLIC KEY-----test-----END PUBLIC KEY-----',
            ],
        ], $overrides);
    }
}
