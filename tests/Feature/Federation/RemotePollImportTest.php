<?php

namespace Tests\Feature\Federation;

use App\Application\Services\DomainBlockManager;
use App\Domain\Comments\Comment;
use App\Domain\Polls\RemotePoll;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Inbox\InboxActivityProcessor;
use App\Federation\Inbox\InboxItem;
use App\Federation\Inbox\RemoteNoteDocumentFetcher;
use App\Federation\Outbox\RemoteFeaturedPostsFetcher;
use App\Federation\Outbox\RemoteOutboxFetcher;
use App\Federation\Posts\RemotePostRefresher;
use App\Federation\Replies\RemoteRepliesFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class RemotePollImportTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    private Actor $author;

    private Actor $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 8)->setTime(12, 0));
        Queue::fake();
        Http::preventStrayRequests();
        $this->author = $this->createRemoteActor('alice', 'remote.example');
        $this->viewer = $this->createFullAccount('bob')->actor;
        Follow::create(['follower_id' => $this->viewer->id, 'following_id' => $this->author->id, 'status' => 'accepted', 'requested_at' => now(), 'accepted_at' => now()]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_create_update_and_duplicate_replay_keep_one_poll_and_stable_options(): void
    {
        $create = $this->item($this->fixture('single-create'), '2026-10-08 10:00:00');
        $update = $this->item($this->fixture('single-update'), '2026-10-08 10:05:00');
        $this->assertSame('processed', $this->process($create));
        $ids = RemotePoll::sole()->options->pluck('id')->all();
        $this->assertSame('processed', $this->process($update));
        $this->assertSame('ignored', $this->process($create));
        $this->assertSame('ignored', $this->process($update));
        $poll = RemotePoll::sole();
        $this->assertSame(1, Post::count());
        $this->assertSame(46, $poll->voters_count);
        $this->assertSame([20, 15, 7, 4], $poll->options->pluck('votes_count')->all());
        $this->assertSame($ids, $poll->options->pluck('id')->all());
        $this->assertSame($update->id, $poll->source_item_id);
    }

    public function test_update_without_create_imports_and_older_create_cannot_reset_results(): void
    {
        $this->assertSame('processed', $this->process($this->item($this->fixture('single-update'), '2026-10-08 10:05:00')));
        $this->process($this->item($this->fixture('single-create'), '2026-10-08 10:00:00'));
        $this->assertSame(46, RemotePoll::sole()->voters_count);
    }

    public function test_original_inbox_id_breaks_equal_receipt_time_ties(): void
    {
        $old = $this->item($this->fixture('single-create'), '2026-10-08 10:00:00');
        $new = $this->item($this->fixture('single-update'), '2026-10-08 10:00:00');
        $this->assertGreaterThan($old->id, $new->id);
        $this->process($new);
        $this->process($old);
        $this->assertSame(46, RemotePoll::sole()->voters_count);
    }

    public function test_remote_updated_version_overrides_receipt_order_and_old_versions_are_ignored(): void
    {
        $first = $this->fixture('single-create');
        $first['object']['updated'] = '2026-10-08T10:01:00Z';
        $this->process($this->item($first, '2026-10-08 10:05:00'));
        $new = $this->fixture('single-update');
        $new['object']['updated'] = '2026-10-08T10:02:00Z';
        $this->process($this->item($new, '2026-10-08 10:04:00'));
        $this->process($this->item($first, '2026-10-08 10:06:00'));
        $this->assertSame(46, RemotePoll::sole()->voters_count);
    }

    public function test_newer_snapshot_can_reduce_counts_and_missing_counts_remain_unknown(): void
    {
        $this->process($this->item($this->fixture('single-update'), '2026-10-08 10:00:00'));
        $next = $this->fixture('single-update');
        $next['object']['votersCount'] = 40;
        $next['object']['oneOf'][0]['replies']['totalItems'] = 14;
        $this->process($this->item($next, '2026-10-08 10:01:00'));
        $this->assertSame(40, RemotePoll::sole()->voters_count);
        $unknown = $this->fixture('open-unknown-results');
        $this->process($this->item($unknown, '2026-10-08 10:02:00'));
        $this->assertNull(RemotePoll::sole()->voters_count);
        $this->assertNull(RemotePoll::sole()->options->first()->votes_count);
        $this->assertNotNull(RemotePoll::sole()->ends_at);
    }

    public function test_multiple_choice_and_explicit_closure_do_not_reopen_after_a_later_snapshot(): void
    {
        $this->process($this->item($this->fixture('multiple-update'), '2026-10-08 10:00:00'));
        $this->assertTrue(RemotePoll::sole()->multiple);
        $this->assertSame(7, RemotePoll::sole()->voters_count);
        $closed = $this->fixture('closed-update');
        $closed['object']['closed'] = true;
        $this->process($this->item($closed, '2026-10-08 10:01:00'));
        $later = $this->fixture('single-update');
        $later['object']['endTime'] = '2026-10-20T10:00:00Z';
        $this->process($this->item($later, '2026-10-08 10:02:00'));
        $this->assertTrue(RemotePoll::sole()->isClosed());
    }

    public function test_swapping_and_renaming_options_preserves_exact_identity_and_personal_snapshots(): void
    {
        $this->process($this->item($this->fixture('single-create'), '2026-10-08 10:00:00'));
        $poll = RemotePoll::sole();
        $ids = $poll->options->pluck('id', 'name');
        $vote = $poll->votes()->create(['actor_id' => $this->viewer->id]);
        $choice = $vote->choices()->create(['position' => 0, 'name' => 'Sabato']);
        $next = $this->fixture('single-update');
        [$next['object']['oneOf'][0], $next['object']['oneOf'][1]] = [$next['object']['oneOf'][1], $next['object']['oneOf'][0]];
        $this->process($this->item($next, '2026-10-08 10:01:00'));
        $this->assertSame($ids['Domenica'], $poll->fresh()->options->first()->id);
        $next['object']['oneOf'][1]['name'] = 'Venerdì';
        $this->process($this->item($next, '2026-10-08 10:02:00'));
        $this->assertNotContains($ids['Sabato'], $poll->fresh()->options->pluck('id')->all());
        $this->assertSame('Sabato', $choice->fresh()->name);
    }

    public function test_malformed_question_is_ignored_without_mutating_existing_state(): void
    {
        $bad = $this->fixture('single-create');
        $bad['object']['anyOf'] = [];
        $this->assertSame('ignored', $this->process($this->item($bad, '2026-10-08 10:00:00')));
        $this->assertSame(0, Post::count());
        $this->process($this->item($this->fixture('single-update'), '2026-10-08 10:01:00'));
        $before = Post::sole()->body;
        $bad['object']['content'] = 'Changed by malformed poll';
        $this->assertSame('ignored', $this->process($this->item($bad, '2026-10-08 10:02:00')));
        $this->assertSame($before, Post::sole()->body);
        $this->assertSame(46, RemotePoll::sole()->voters_count);
    }

    public function test_votes_never_become_comments_or_direct_messages_even_for_unknown_targets(): void
    {
        $this->process($this->item($this->fixture('single-create'), '2026-10-08 10:00:00'));
        $activity = $this->fixture('vote-create');
        $activity['actor'] = $this->author->uri;
        $activity['object']['attributedTo'] = $this->author->uri;
        $activity['object']['to'] = $this->viewer->uri;
        $activity['to'] = $this->viewer->uri;
        $this->assertSame('ignored', $this->process($this->item($activity, '2026-10-08 10:01:00')));
        $activity['object']['inReplyTo'] = 'https://remote.example/unknown';
        $this->assertSame('ignored', $this->process($this->item($activity, '2026-10-08 10:02:00')));
        $this->assertSame(1, Post::count());
        $this->assertSame(0, Comment::count());
        $this->assertNull(Post::sole()->conversation_id);
    }

    public function test_spoofed_author_and_deleted_post_are_protected(): void
    {
        $spoofed = $this->fixture('single-create');
        $spoofed['object']['attributedTo'] = 'https://remote.example/users/mallory';
        $this->assertSame('ignored', $this->process($this->item($spoofed, '2026-10-08 10:00:00')));
        $this->process($this->item($this->fixture('single-create'), '2026-10-08 10:01:00'));
        Post::sole()->update(['status' => 'deleted']);
        $this->assertSame('ignored', $this->process($this->item($this->fixture('single-update'), '2026-10-08 10:02:00')));
        $this->assertSame('deleted', Post::sole()->status);
        $this->assertSame(0, RemotePoll::sole()->voters_count);
    }

    public function test_blocked_origin_and_irrelevant_author_are_ignored(): void
    {
        Follow::query()->delete();
        $this->assertSame('ignored', $this->process($this->item($this->fixture('single-create'), '2026-10-08 10:00:00')));
        $this->viewer->user->forceFill(['is_admin' => true])->save();
        app(DomainBlockManager::class)->block($this->viewer->user, 'remote.example');
        $this->assertSame('ignored', $this->process($this->item($this->fixture('single-update'), '2026-10-08 10:01:00')));
        $this->assertSame(0, Post::count());
    }

    public function test_follower_only_poll_keeps_its_visibility(): void
    {
        $activity = $this->fixture('single-create');
        $activity['to'] = $activity['object']['to'] = [$this->author->uri.'/followers'];
        $activity['cc'] = $activity['object']['cc'] = [];
        $this->assertSame('processed', $this->process($this->item($activity, '2026-10-08 10:00:00')));
        $this->assertSame('followers', Post::sole()->visibility);
        $this->assertSame(0, Post::query()->visibleTo(null)->count());
        $this->assertSame(1, Post::query()->visibleTo($this->viewer)->count());
    }

    public function test_fetch_by_uri_and_outbox_import_poll_metadata(): void
    {
        $activity = $this->fixture('single-create');
        $uri = $activity['object']['id'];
        Http::fake([$uri => Http::response($activity['object'])]);
        $activity['object'] = $uri;
        $this->assertSame('processed', $this->process($this->item($activity, '2026-10-08 10:00:00')));
        $this->assertSame(1, RemotePoll::count());
        $next = $this->fixture('single-update')['object'];
        $next['id'] .= '-outbox';
        Http::fake([$this->author->endpoints->outbox => Http::response(['type' => 'OrderedCollection', 'orderedItems' => [$next]])]);
        app(RemoteOutboxFetcher::class)->fetchRecentContent($this->author);
        $this->assertSame(2, RemotePoll::count());
    }

    public function test_announce_of_a_poll_uses_shared_import_flow(): void
    {
        $activity = $this->fixture('single-create');
        $activity['type'] = 'Announce';
        $this->assertSame('processed', $this->process($this->item($activity, '2026-10-08 10:00:00')));
        $this->assertSame(1, RemotePoll::count());
        $this->assertSame(1, Post::sole()->announces()->count());
    }

    public function test_refresh_and_replies_refresh_update_poll_without_importing_vote_notes(): void
    {
        $this->process($this->item($this->fixture('single-create'), '2026-10-08 10:00:00'));
        $document = $this->fixture('single-update')['object'];
        $document['replies'] = ['type' => 'Collection', 'items' => [$this->fixture('vote-create')['object']]];
        Http::fake(function () use (&$document) {
            return Http::response($document);
        });
        app(RemotePostRefresher::class)->refresh(Post::sole());
        $this->assertSame(46, RemotePoll::sole()->voters_count);
        $this->assertSame(0, Comment::count());
        $document['votersCount'] = 47;
        app(RemoteRepliesFetcher::class)->fetchReplies(Post::sole(), force: true);
        $this->assertSame(47, RemotePoll::sole()->voters_count);
    }

    public function test_updates_of_cached_poll_remain_relevant_after_unfollowing(): void
    {
        $this->process($this->item($this->fixture('single-create'), '2026-10-08 10:00:00'));
        Follow::query()->delete();
        $this->assertSame('processed', $this->process($this->item($this->fixture('single-update'), '2026-10-08 10:01:00')));
        $this->assertSame(46, RemotePoll::sole()->voters_count);
    }

    public function test_inbox_replay_in_same_second_as_fetch_does_not_overwrite_the_fetched_snapshot(): void
    {
        $note = $this->fixture('single-update')['object'];
        app(RemoteOutboxFetcher::class)->upsertPublicPost($note, $this->author);
        $this->assertNull(RemotePoll::sole()->source_item_id);
        $this->assertSame('ignored', $this->process($this->item($this->fixture('single-create'), now()->format('Y-m-d H:i:s'))));
        $this->assertSame(46, RemotePoll::sole()->voters_count);
    }

    public function test_expired_poll_cannot_reopen_by_extending_end_time(): void
    {
        $this->process($this->item($this->fixture('single-create'), '2026-10-08 10:00:00'));
        $this->travelTo(now()->addDays(3));
        $next = $this->fixture('single-update');
        $next['object']['endTime'] = '2026-10-20T10:00:00Z';
        $this->process($this->item($next, '2026-10-11 10:00:00'));
        $this->assertTrue(RemotePoll::sole()->isClosed());
    }

    public function test_fetch_rejects_a_question_with_a_different_document_id(): void
    {
        $question = $this->fixture('single-create')['object'];
        $requested = $question['id'].'-different';
        Http::fake([$requested => Http::response($question)]);
        $this->assertNull(app(RemoteNoteDocumentFetcher::class)->fetch($requested));
        $activity = $this->fixture('single-create');
        $activity['object'] = $requested;
        $this->assertSame('ignored', $this->process($this->item($activity, '2026-10-08 10:00:00')));
        $this->assertSame(0, RemotePoll::count());
    }

    public function test_third_party_announce_verifies_the_canonical_poll(): void
    {
        $booster = $this->createRemoteActor('booster');
        Follow::create(['follower_id' => $this->viewer->id, 'following_id' => $booster->id, 'status' => 'accepted', 'requested_at' => now(), 'accepted_at' => now()]);
        $activity = $this->fixture('single-create');
        $activity['type'] = 'Announce';
        $activity['actor'] = $booster->uri;
        $activity['object']['votersCount'] = 999;
        $canonical = $this->fixture('single-update')['object'];
        Http::fake([$canonical['id'] => Http::response($canonical)]);
        $item = $this->item($activity, '2026-10-08 10:00:00');
        $item->update(['actor_uri' => $booster->uri]);
        $this->assertSame('processed', $this->process($item));
        $this->assertSame(46, RemotePoll::sole()->voters_count);
        $this->assertNull(RemotePoll::sole()->source_item_id);
    }

    public function test_uri_fetch_uses_observation_time_instead_of_old_envelope_receipt(): void
    {
        $activity = $this->fixture('single-create');
        $question = $this->fixture('single-update')['object'];
        Http::fake([$question['id'] => Http::response($question)]);
        $activity['object'] = $question['id'];
        $this->assertSame('processed', $this->process($this->item($activity, '2026-10-08 09:00:00')));
        $this->assertNull(RemotePoll::sole()->source_item_id);
        $this->assertSame('ignored', $this->process($this->item($this->fixture('single-create'), '2026-10-08 10:00:00')));
        $this->assertSame(46, RemotePoll::sole()->voters_count);
    }

    public function test_featured_collection_imports_a_poll_without_personal_data(): void
    {
        $url = $this->author->uri.'/collections/featured';
        $this->author->endpoints()->update(['featured' => $url]);
        $question = $this->fixture('single-create')['object'];
        Http::fake([$url => Http::response(['type' => 'OrderedCollection', 'orderedItems' => [$question]])]);
        app(RemoteFeaturedPostsFetcher::class)->refreshIfStale($this->author->fresh());
        $this->assertSame(1, RemotePoll::count());
        $this->assertSame([Post::sole()->id], $this->author->fresh()->featured_post_ids);
        $this->assertSame(0, RemotePoll::sole()->votes()->count());
    }

    public function test_group_outbox_verifies_and_imports_the_canonical_question(): void
    {
        $group = $this->createRemoteActor('group', 'group.example', ['type' => Actor::TYPE_GROUP, 'events_fetched_at' => now()]);
        $question = $this->fixture('single-update')['object'];
        $embedded = $question;
        $embedded['votersCount'] = 999;
        Http::fake([
            $group->endpoints->outbox => Http::response(['type' => 'OrderedCollection', 'orderedItems' => [['type' => 'Announce', 'object' => $embedded]]]),
            $question['id'] => Http::response($question),
        ]);
        app(RemoteOutboxFetcher::class)->fetchRecentContent($group);
        $this->assertSame(46, RemotePoll::sole()->voters_count);
        $this->assertSame($group->id, Post::sole()->announces->sole()->actor_id);
    }

    public function test_embedded_quote_verifies_the_canonical_question(): void
    {
        $activity = $this->fixture('single-create');
        $question = $this->fixture('single-update')['object'];
        $activity['object']['id'] .= '-quoting';
        $activity['object']['type'] = 'Note';
        $activity['object']['quote'] = $question;
        $activity['object']['quote']['votersCount'] = 999;
        Http::fake([$question['id'] => Http::response($question)]);
        $this->assertSame('processed', $this->process($this->item($activity, '2026-10-08 10:00:00')));
        $this->assertSame(46, RemotePoll::sole()->voters_count);
        $quoting = Post::where('uri', $activity['object']['id'])->sole();
        $this->assertSame(RemotePoll::sole()->post_id, $quoting->quoted_post_id);
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/Federation/Polls/'.$name.'.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function item(array $activity, string $received): InboxItem
    {
        return InboxItem::create(['is_shared' => true, 'remote_activity_uri' => 'https://remote.example/activities/'.Str::uuid(), 'activity_type' => $activity['type'], 'actor_uri' => $this->author->uri, 'payload' => json_encode($activity, JSON_THROW_ON_ERROR), 'signature_valid' => true, 'status' => 'pending', 'received_at' => $received]);
    }

    private function process(InboxItem $item): string
    {
        return app(InboxActivityProcessor::class)->process($item);
    }
}
