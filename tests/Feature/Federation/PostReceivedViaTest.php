<?php

namespace Tests\Feature\Federation;

use App\Application\Services\PostComposer;
use App\Domain\Federation\Relay;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Inbox\InboxActivityProcessor;
use App\Federation\Inbox\InboxItem;
use App\Federation\Inbox\RemoteNoteUpserter;
use App\Federation\Serialization\NoteSerializer;
use App\Http\Resources\Rest\V1\PostResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class PostReceivedViaTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function note(Actor $author, string $id = 'post'): array
    {
        return [
            'id' => $author->uri.'/posts/'.$id, 'type' => 'Note',
            'attributedTo' => $author->uri, 'content' => '<p>Original</p>',
            'published' => now()->subHour()->toAtomString(),
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        ];
    }

    private function follow(Actor $actor): void
    {
        $viewer = $this->createFullAccount('sourceviewer');
        Follow::query()->create([
            'follower_id' => $viewer->actor->id, 'following_id' => $actor->id,
            'status' => Follow::STATUS_ACCEPTED, 'requested_at' => now(), 'accepted_at' => now(),
        ]);
    }

    private function process(Actor $actor, array|string $object, string $type = 'Announce', ?Relay $relay = null, ?array $via = null): string
    {
        $activity = ['id' => $actor->uri.'/activities/'.uniqid(), 'actor' => $actor->uri, 'type' => $type, 'object' => $object];
        $item = InboxItem::query()->create([
            'remote_activity_uri' => $activity['id'], 'activity_type' => $type,
            'actor_uri' => $actor->uri, 'payload' => json_encode($activity),
            'signature_valid' => true, 'relay_id' => $relay?->id,
            'received_via' => $via, 'received_at' => now(),
        ]);

        return app(InboxActivityProcessor::class)->process($item->fresh());
    }

    private function relay(Actor $actor): Relay
    {
        return Relay::query()->create([
            'actor_uri' => $actor->uri, 'inbox_url' => $actor->endpoints->inbox,
            'inbox_url_hash' => hash('sha256', $actor->endpoints->inbox),
            'protocol' => Relay::PROTOCOL_MASTODON, 'state' => Relay::STATE_ACCEPTED,
            'receive_enabled' => true, 'publish_enabled' => false,
        ]);
    }

    public static function announcements(): iterable
    {
        foreach (['relay', 'community', 'both', 'groupRelay'] as $kind) {
            foreach ([true, false] as $embedded) {
                yield $kind.($embedded ? ' embedded' : ' fetched') => [$kind, $embedded];
            }
        }
    }

    #[DataProvider('announcements')]
    public function test_announce_preserves_observed_sources_through_fetch(string $kind, bool $embedded): void
    {
        $author = $this->createRemoteActor('author', 'origin.example');
        $transport = $this->createRemoteActor('relay', 'relay.example', [
            'type' => $kind === 'groupRelay' ? Actor::TYPE_GROUP : Actor::TYPE_APPLICATION,
        ]);
        $announcer = in_array($kind, ['community', 'both'], true)
            ? $this->createRemoteActor('community', 'forum.example', ['type' => Actor::TYPE_GROUP])
            : $transport;
        $relay = $kind === 'community' ? null : $this->relay($transport);
        if ($kind === 'community') {
            $this->follow($announcer);
        }
        $expected = [];
        if ($relay !== null) {
            $expected['relay'] = $transport->uri;
        }
        if (in_array($kind, ['community', 'both'], true)) {
            $expected['community'] = $announcer->uri;
        }
        $note = $this->note($author);
        Http::fake([$note['id'] => Http::response($note, 200, ['Content-Type' => 'application/activity+json'])]);
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($announcer, $embedded ? $note : $note['id'], relay: $relay));
        $post = Post::query()->where('uri', $note['id'])->sole();
        $this->assertEquals($expected, $post->received_via);
        $this->assertSame($author->id, $post->actor_id);
        $this->assertArrayNotHasKey('received_via', NoteSerializer::forPost($post));
        $this->assertArrayNotHasKey('received_via', (new PostResource($post))->resolve(request()));
        if ($embedded) {
            Http::assertNothingSent();
        } else {
            Http::assertSentCount(1);
        }
    }

    public function test_forwarded_create_uses_queued_transport_without_changing_the_author(): void
    {
        $author = $this->createRemoteActor('forwarded', 'origin.example');
        $this->follow($author);
        $via = ['community' => 'https://forum.example/c/cooking'];
        $note = $this->note($author);
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($author, $note, 'Create', via: $via));
        $post = Post::query()->where('uri', $note['id'])->sole();
        $this->assertSame($via, $post->received_via);
        $this->assertSame($author->id, $post->actor_id);
    }

    public function test_ordinary_acquisition_stays_null_after_later_announces_and_updates(): void
    {
        $author = $this->createRemoteActor('ordinary', 'origin.example');
        $this->follow($author);
        $note = $this->note($author);
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($author, $note, 'Create'));
        $post = Post::query()->where('uri', $note['id'])->sole();
        $this->assertNull($post->received_via);
        $transport = $this->createRemoteActor('relay', 'relay.example', ['type' => Actor::TYPE_APPLICATION]);
        $relay = $this->relay($transport);
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($transport, $note, relay: $relay));
        $note['content'] = '<p>Updated</p>';
        $note['updated'] = now()->toAtomString();
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($author, $note, 'Update', $relay));
        $this->assertNull($post->fresh()->received_via);
        $this->assertSame('Updated', $post->fresh()->body);
    }

    public function test_first_provenance_survives_updates_retries_and_a_different_source(): void
    {
        $author = $this->createRemoteActor('stable', 'origin.example');
        $note = $this->note($author);
        $first = ['community' => 'https://first.example/c/group', 'relay' => 'https://first.example/relay'];
        $upserter = app(RemoteNoteUpserter::class);
        $post = $upserter->upsertPost($note, $note['id'], $author, 'Original', Carbon::parse($note['published']), trackDelivery: false, receivedVia: $first);
        foreach ([null, ['relay' => 'https://other.example/relay'], $first] as $later) {
            $updated = $upserter->upsertPost($note, $note['id'], $author, 'Updated', Carbon::parse($note['published']), trackDelivery: false, receivedVia: $later);
            $this->assertSame($post->id, $updated->id);
            $this->assertEquals($first, $post->fresh()->received_via);
        }
    }

    public function test_rejected_content_and_rollback_do_not_leave_a_source_record(): void
    {
        $author = $this->createRemoteActor('rejected', 'origin.example');
        $transport = $this->createRemoteActor('relay', 'relay.example', ['type' => Actor::TYPE_APPLICATION]);
        $relay = $this->relay($transport);
        $note = $this->note($author);
        $note['to'] = [$author->uri.'/followers'];
        $this->assertSame(InboxItem::STATUS_IGNORED, $this->process($transport, $note, relay: $relay));
        $this->assertDatabaseMissing('posts', ['uri' => $note['id']]);
        $note['to'] = ['https://www.w3.org/ns/activitystreams#Public'];
        try {
            DB::transaction(function () use ($author, $note): void {
                app(RemoteNoteUpserter::class)->upsertPost($note, $note['id'], $author, 'Rollback', now(), trackDelivery: false, receivedVia: ['relay' => 'https://relay.example/actor']);
                throw new \RuntimeException('Rollback');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('Rollback', $e->getMessage());
        }
        $this->assertDatabaseMissing('posts', ['uri' => $note['id']]);
        $post = app(RemoteNoteUpserter::class)->upsertPost($note, $note['id'], $author, 'Retry', now(), trackDelivery: false);
        $this->assertNull($post->fresh()->received_via);
    }

    public function test_local_posts_default_to_null_and_migration_round_trip_preserves_them(): void
    {
        $user = $this->createFullAccount('localsource');
        $post = app(PostComposer::class)->compose($user->actor, ['body' => 'Local']);
        $this->assertNull($post->fresh()->received_via);
        $this->assertNull(DB::table('posts')->where('id', $post->id)->value('received_via'));
        $migration = require database_path('migrations/2026_11_03_000000_add_received_via_to_posts_and_inbox_items.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('posts', 'received_via'));
        $migration->up();
        $this->assertSame('Local', $post->fresh()->body);
        $this->assertNull($post->fresh()->received_via);
        $this->assertTrue(Schema::hasColumn('inbox_items', 'received_via'));
    }

    public function test_a_quoted_post_does_not_inherit_the_quoting_posts_transport(): void
    {
        $author = $this->createRemoteActor('quoting', 'origin.example');
        $transport = $this->createRemoteActor('relay', 'relay.example', ['type' => Actor::TYPE_APPLICATION]);
        $relay = $this->relay($transport);
        $quote = $this->note($author, 'quoted');
        $note = $this->note($author, 'quoting');
        $note['quoteUrl'] = $quote['id'];
        Http::fake([$quote['id'] => Http::response($quote, 200, ['Content-Type' => 'application/activity+json'])]);
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($transport, $note, relay: $relay));
        $post = Post::query()->where('uri', $note['id'])->sole();
        $quoted = Post::query()->where('uri', $quote['id'])->sole();
        $this->assertSame($quoted->id, $post->quoted_post_id);
        $this->assertSame(['relay' => $transport->uri], $post->received_via);
        $this->assertNull($quoted->received_via);
    }
}
