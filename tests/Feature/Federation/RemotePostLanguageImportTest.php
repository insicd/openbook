<?php

namespace Tests\Feature\Federation;

use App\Application\Services\FollowManager;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Inbox\InboxActivityProcessor;
use App\Federation\Inbox\InboxItem;
use App\Federation\Inbox\RemoteNoteUpserter;
use App\Federation\Inbox\RemotePostObject;
use App\Federation\Serialization\NoteSerializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class RemotePostLanguageImportTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function process(array $activity, Actor $signer): string
    {
        $item = InboxItem::query()->create([
            'remote_activity_uri' => $activity['id'], 'activity_type' => $activity['type'],
            'actor_uri' => $signer->uri, 'payload' => json_encode($activity, JSON_THROW_ON_ERROR),
            'signature_valid' => true, 'status' => InboxItem::STATUS_PENDING, 'received_at' => now(),
        ]);

        return app(InboxActivityProcessor::class)->process($item);
    }

    public function test_inbox_import_changes_clears_and_does_not_restore_stale_language(): void
    {
        $local = $this->createFullAccount('reader');
        $remote = $this->createRemoteActor('author');
        app(FollowManager::class)->follow($local->actor, $remote)
            ->update(['status' => Follow::STATUS_ACCEPTED, 'accepted_at' => now()]);
        $note = [
            'id' => $remote->uri.'/posts/language', 'type' => 'Note', 'attributedTo' => $remote->uri,
            'content' => '<p>Text</p>', 'contentMap' => ['IT-ch' => '<p>Text</p>'],
            'published' => '2026-10-08T10:00:00Z', 'to' => [NoteSerializer::PUBLIC_STREAM],
        ];
        $send = function (array $document, string $type, string $id) use ($remote): void {
            $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process([
                'id' => $remote->uri.'/activities/'.$id, 'type' => $type,
                'actor' => $remote->uri, 'object' => $document,
            ], $remote));
        };
        $send($note, 'Create', 'create');
        $post = Post::query()->where('uri', $note['id'])->firstOrFail();
        $this->assertSame('it-ch', $post->language);
        $note['contentMap'] = ['zh-Hant-TW' => $note['content']];
        $note['updated'] = '2026-10-08T11:00:00Z';
        $send($note, 'Update', 'change');
        $this->assertSame('zh-hant-tw', $post->fresh()->language);
        $note['contentMap']['en'] = $note['content'];
        $note['updated'] = '2026-10-08T12:00:00Z';
        $send($note, 'Update', 'ambiguous');
        $this->assertNull($post->fresh()->language);
        $note['updated'] = '2026-10-08T13:00:00Z';
        $note['contentMap'] = ['fr' => $note['content']];
        $send($note, 'Update', 'restore');
        unset($note['contentMap']);
        $note['updated'] = '2026-10-08T14:00:00Z';
        $send($note, 'Update', 'remove');
        $this->assertNull($post->fresh()->language);
        $note['updated'] = '2026-10-08T13:00:00Z';
        $note['contentMap'] = ['de' => $note['content']];
        $send($note, 'Update', 'stale');
        $this->assertNull($post->fresh()->language);
    }

    public function test_inbox_default_is_inherited_but_not_applied_to_independently_fetched_documents(): void
    {
        $local = $this->createFullAccount('reader');
        $remote = $this->createRemoteActor('author');
        app(FollowManager::class)->follow($local->actor, $remote)
            ->update(['status' => Follow::STATUS_ACCEPTED, 'accepted_at' => now()]);
        $note = [
            'id' => $remote->uri.'/posts/default', 'type' => 'Note', 'attributedTo' => $remote->uri,
            'content' => '<p>Text</p>', 'published' => now()->toAtomString(),
            'to' => [NoteSerializer::PUBLIC_STREAM],
        ];
        $activity = [
            '@context' => ['https://www.w3.org/ns/activitystreams', ['@language' => 'it']],
            'id' => $remote->uri.'/activities/default', 'type' => 'Create',
            'actor' => $remote->uri, 'object' => $note,
        ];
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($activity, $remote));
        $this->assertDatabaseHas('posts', ['uri' => $note['id'], 'language' => 'it']);
        $note['id'] = $remote->uri.'/posts/fetched';
        Http::fake([$note['id'] => Http::response($note)]);
        $activity['id'] .= '/fetched';
        $activity['object'] = $note['id'];
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($activity, $remote));
        $this->assertDatabaseHas('posts', ['uri' => $note['id'], 'language' => null]);
    }

    public function test_shared_upsert_accepts_long_tags_without_changing_selected_body(): void
    {
        $remote = $this->createRemoteActor('author');
        $tag = 'en-x-'.implode('-', array_fill(0, 25, 'abcdefgh'));
        $note = [
            'id' => $remote->uri.'/posts/long', 'contentMap' => [$tag => '<p>Text</p>'],
            'to' => [NoteSerializer::PUBLIC_STREAM],
        ];
        $post = app(RemoteNoteUpserter::class)->upsertPost(
            $note, $note['id'], $remote, RemotePostObject::body($note), Carbon::now(),
            notifyMentions: false, resolveQuote: false,
        );
        $this->assertSame($tag, $post->fresh()->language);
        $this->assertSame('Text', $post->body);

        $migration = require database_path('migrations/2026_10_09_000001_widen_post_language.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot narrow posts.language');
        $migration->down();
    }

    public function test_group_announce_inherits_the_nearest_embedded_language_context(): void
    {
        $local = $this->createFullAccount('groupreader');
        $group = $this->createRemoteActor('group', overrides: ['type' => Actor::TYPE_GROUP]);
        $author = $this->createRemoteActor('originalauthor');
        app(FollowManager::class)->follow($local->actor, $group)
            ->update(['status' => Follow::STATUS_ACCEPTED, 'accepted_at' => now()]);
        $uri = $author->uri.'/posts/shared-language';

        $status = $this->process([
            '@context' => ['https://www.w3.org/ns/activitystreams', ['@language' => 'it']],
            'id' => $group->uri.'/activities/announce-language',
            'type' => 'Announce', 'actor' => $group->uri,
            'to' => [NoteSerializer::PUBLIC_STREAM],
            'object' => [
                '@context' => ['@language' => 'fr'],
                'type' => 'Create', 'actor' => $author->uri,
                'object' => [
                    'id' => $uri, 'type' => 'Note', 'attributedTo' => $author->uri,
                    'content' => '<p>Texte partagé.</p>', 'published' => now()->toAtomString(),
                    'to' => [NoteSerializer::PUBLIC_STREAM],
                ],
            ],
        ], $group);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertDatabaseHas('posts', ['uri' => $uri, 'actor_id' => $author->id, 'language' => 'fr']);
    }
}
