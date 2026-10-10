<?php

namespace Tests\Feature\Federation;

use App\Application\Queries\ActorFeaturedPostsQuery;
use App\Application\Services\CommunityRegistrar;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Actors\RemoteActorResolver;
use App\Federation\Inbox\InboxActivityProcessor;
use App\Federation\Inbox\InboxItem;
use App\Federation\Outbox\RemoteFeaturedPostsFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class RemoteFeaturedPostsTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    private function remote(): Actor
    {
        $actor = $this->createRemoteActor('pinnedauthor', overrides: ['posts_fetched_at' => now(), 'events_fetched_at' => now()]);
        $actor->endpoints->update(['featured' => $actor->uri.'/collections/featured']);

        return $actor;
    }

    private function note(Actor $actor, string $slug, array $fields = []): array
    {
        return array_replace([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $actor->uri.'/statuses/'.$slug, 'type' => 'Note',
            'attributedTo' => $actor->uri, 'content' => '<p>Fissato '.$slug.'</p>',
            'published' => '2026-10-01T12:00:00Z',
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'cc' => [$actor->uri.'/followers'], 'contentMap' => ['it' => '<p>Fissato '.$slug.'</p>'],
        ], $fields);
    }

    public function test_imports_paged_inline_and_referenced_posts_in_collection_order_with_ttl(): void
    {
        $actor = $this->remote();
        $first = $actor->endpoints->featured.'?page=1';
        $second = $actor->endpoints->featured.'?page=2';
        $note = $this->note($actor, 'old');
        Http::fake([
            $actor->endpoints->featured => Http::response(['type' => 'OrderedCollection', 'first' => $first]),
            $first => Http::response(['type' => 'OrderedCollectionPage', 'orderedItems' => [$note['id']], 'next' => $second]),
            $second => Http::response(['type' => 'OrderedCollectionPage', 'orderedItems' => [$this->note($actor, 'new'), $note]]),
            $note['id'] => Http::response($note),
        ]);
        $fetcher = app(RemoteFeaturedPostsFetcher::class);
        $fetcher->refreshIfStale($actor);
        $posts = app(ActorFeaturedPostsQuery::class)->forActor($actor->fresh(), null);
        $this->assertSame(['Fissato old', 'Fissato new'], $posts->pluck('body')->all());
        $this->assertSame(['it', 'it'], $posts->pluck('language')->all());
        $this->assertDatabaseCount('posts', 2);
        $this->assertDatabaseCount('notifications', 0);
        Http::assertSentCount(4);
        $fetcher->refreshIfStale($actor->fresh());
        Http::assertSentCount(4);
    }

    public function test_failure_preserves_snapshot_and_successful_removal_does_not_delete_posts(): void
    {
        $actor = $this->remote();
        Http::fake([$actor->endpoints->featured => Http::sequence()
            ->push(['type' => 'Collection', 'items' => [$this->note($actor, 'keep')]])
            ->push('', 503)
            ->push(['type' => 'Collection', 'items' => []]),
        ]);
        $fetcher = app(RemoteFeaturedPostsFetcher::class);
        $fetcher->refreshIfStale($actor);
        $ids = $actor->fresh()->featured_post_ids;
        $actor->update(['featured_fetched_at' => null]);
        $fetcher->refreshIfStale($actor);
        $this->assertSame($ids, $actor->fresh()->featured_post_ids);
        $actor->update(['featured_fetched_at' => null]);
        $fetcher->refreshIfStale($actor);
        $this->assertSame([], $actor->fresh()->featured_post_ids);
        $this->assertDatabaseCount('posts', 1);
    }

    public function test_rejects_private_foreign_reply_deleted_and_mismatching_objects(): void
    {
        $actor = $this->remote();
        $other = $this->createRemoteActor('unrelated');
        $deleted = Post::query()->create(['actor_id' => $actor->id, 'uri' => $this->note($actor, 'deleted')['id'],
            'body' => 'Cancellato', 'published_at' => now(), 'status' => Post::STATUS_DELETED, 'visibility' => Post::VISIBILITY_PUBLIC]);
        $mismatch = $this->note($actor, 'reference')['id'];
        $foreignUri = 'https://different.example/posts/forged';
        Http::fake([
            $actor->endpoints->featured => Http::response(['type' => 'OrderedCollection', 'orderedItems' => [
                $this->note($actor, 'private', ['to' => [$actor->uri.'/followers'], 'cc' => []]),
                $this->note($actor, 'dm', ['directMessage' => true]),
                $this->note($actor, 'foreign', ['attributedTo' => $other->uri]),
                $this->note($actor, 'reply', ['inReplyTo' => $actor->uri.'/statuses/root']),
                $this->note($actor, 'deleted'), $mismatch,
                $this->note($actor, 'forged', ['id' => $foreignUri]),
                'http://127.0.0.1/private',
            ]]),
            $mismatch => Http::response($this->note($actor, 'different')),
            $foreignUri => Http::response('', 404),
        ]);
        app(RemoteFeaturedPostsFetcher::class)->refreshIfStale($actor);
        $this->assertSame([], $actor->fresh()->featured_post_ids);
        $this->assertDatabaseCount('posts', 1);
        $this->assertSame(Post::STATUS_DELETED, $deleted->fresh()->status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
    }

    public function test_actor_update_changes_endpoint_and_clears_cached_membership(): void
    {
        $actor = $this->remote();
        $actor->update(['featured_post_ids' => ['old'], 'featured_fetched_at' => now()]);
        $document = ['id' => $actor->uri, 'type' => 'Person', 'preferredUsername' => $actor->preferred_username,
            'inbox' => $actor->uri.'/inbox', 'publicKey' => ['publicKeyPem' => $actor->key->public_key],
            'featured' => ['id' => $actor->uri.'/new-featured']];
        $resolver = app(RemoteActorResolver::class);
        $updated = $resolver->applyRemoteDocument($document, $actor->uri);
        $this->assertSame($actor->uri.'/new-featured', $updated->endpoints->featured);
        $this->assertNull($updated->featured_post_ids);
        $this->assertNull($updated->featured_fetched_at);
        unset($document['featured']);
        $updated = $resolver->applyRemoteDocument($document, $actor->uri);
        $this->assertNull($updated->endpoints->featured);
    }

    public function test_authenticated_add_remove_only_invalidate_the_signers_own_collection(): void
    {
        $actor = $this->remote();
        foreach (['Add', 'Remove'] as $type) {
            foreach ([false, true] as $valid) {
                $actor->refresh()->update(['featured_fetched_at' => now()]);
                $activity = ['id' => $actor->uri.'/'.$type.'/'.(int) $valid, 'type' => $type, 'actor' => $actor->uri,
                    'target' => $valid ? $actor->endpoints->featured : 'https://other.example/featured',
                    'object' => $this->note($actor, 'one')['id']];
                $item = InboxItem::query()->create(['is_shared' => true, 'remote_activity_uri' => $activity['id'],
                    'activity_type' => $type, 'actor_uri' => $actor->uri, 'payload' => json_encode($activity, JSON_THROW_ON_ERROR),
                    'signature_valid' => true, 'status' => InboxItem::STATUS_PENDING, 'received_at' => now()]);
                $this->assertSame($valid ? InboxItem::STATUS_PROCESSED : InboxItem::STATUS_IGNORED,
                    app(InboxActivityProcessor::class)->process($item));
                $this->assertSame($valid, $actor->fresh()->featured_fetched_at === null);
            }
        }
    }

    public function test_profile_tab_order_cards_empty_state_and_visibility(): void
    {
        $user = $this->createFullAccount('pinsreader');
        $actor = $this->remote();
        Http::fake([$actor->endpoints->featured => Http::response(['type' => 'OrderedCollection', 'orderedItems' => [$this->note($actor, 'visible')]]),
            '*' => Http::response('', 404),
        ]);
        // Import before visiting: profile backfill is unrelated to pin membership.
        app(RemoteFeaturedPostsFetcher::class)->refreshIfStale($actor);
        $post = Post::query()->firstOrFail();
        $this->actingAs($user)->get(route('actors.show', $actor))->assertOk()
            ->assertSeeInOrder(['>'.__('openbook.profile.tab_posts').'</a>', '>'.__('openbook.profile.pinned_posts').'</a>', '>'.__('openbook.profile.tab_photos').'</a>'], false);
        $this->get(route('actors.featured', $actor))->assertOk()->assertSee('Fissato visible')
            ->assertSee('aria-selected="true">'.__('openbook.profile.pinned_posts'), false);
        $post->update(['visibility' => Post::VISIBILITY_DIRECT]);
        $this->get(route('actors.show', $actor))->assertOk()->assertDontSee(route('actors.featured', $actor), false);
        $this->get(route('actors.featured', $actor))->assertOk()->assertDontSee('Fissato visible')
            ->assertSee(__('openbook.profile.no_featured_posts'));
        $local = $user->actor;
        $this->get(route('actors.featured', $local))->assertRedirect(route('profile.show', $local->preferred_username));
    }

    public function test_snapshot_limit_and_signed_fetch(): void
    {
        $user = $this->createFullAccount('signedpins');
        $actor = $this->remote();
        $this->actingAs($user);
        $notes = [];
        for ($i = 0; $i < 25; $i++) {
            $notes[] = $this->note($actor, (string) $i);
        }
        Http::fake([$actor->endpoints->featured => Http::response(['type' => 'OrderedCollection', 'orderedItems' => $notes])]);
        app(RemoteFeaturedPostsFetcher::class)->refreshIfStale($actor);
        $this->assertCount(20, $actor->fresh()->featured_post_ids);
        $this->assertDatabaseCount('posts', 20);
        Http::assertSent(fn ($request) => $request->url() === $actor->endpoints->featured && $request->hasHeader('Signature'));
    }

    public function test_private_community_visibility_is_preserved_even_for_public_pins(): void
    {
        $owner = $this->createFullAccount('pinscommunityowner');
        $viewer = $this->createFullAccount('pinscommunityviewer');
        $community = app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'private-pins', 'name' => 'Private pins', 'is_private' => true,
        ]);
        $actor = $this->remote();
        $post = Post::query()->create(['actor_id' => $actor->id, 'uri' => $actor->uri.'/private-community-post',
            'body' => 'Riservato alla community', 'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC, 'published_at' => now(), 'community_id' => $community->id]);
        $actor->update(['featured_post_ids' => [$post->id]]);
        $query = app(ActorFeaturedPostsQuery::class);
        $this->assertTrue($query->forActor($actor, $viewer->actor)->isEmpty());
        $this->assertTrue($query->forActor($actor, null)->isEmpty());
        Follow::query()->create(['follower_id' => $viewer->actor->id,
            'following_id' => $community->actor_id, 'status' => 'accepted', 'requested_at' => now(), 'accepted_at' => now()]);
        $this->assertSame([$post->id], $query->forActor($actor, $viewer->actor)->pluck('id')->all());
    }

    public function test_invalid_or_incomplete_collections_keep_previous_snapshot_and_bound_requests(): void
    {
        $actor = $this->remote();
        $actor->update(['featured_post_ids' => ['previous']]);
        $url = $actor->endpoints->featured;
        Http::fake([
            $url => Http::response(['type' => 'OrderedCollection', 'first' => $url.'?page=1']),
            $url.'?page=1' => Http::response(['type' => 'OrderedCollectionPage', 'orderedItems' => [], 'next' => $url.'?page=2']),
            $url.'?page=2' => Http::response(['type' => 'OrderedCollectionPage', 'orderedItems' => [], 'next' => $url.'?page=3']),
            '*' => Http::response('', 500),
        ]);
        app(RemoteFeaturedPostsFetcher::class)->refreshIfStale($actor);
        $this->assertSame(['previous'], $actor->fresh()->featured_post_ids);
        Http::assertSentCount(3);
    }
}
