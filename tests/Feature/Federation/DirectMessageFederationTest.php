<?php

namespace Tests\Feature\Federation;

use App\Domain\Messaging\Conversation;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use App\Federation\Inbox\InboxActivityProcessor;
use App\Federation\Inbox\InboxItem;
use App\Federation\Inbox\RemoteNoteUpserter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class DirectMessageFederationTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_visibility_from_audience_treats_mastodon_style_to_as_direct(): void
    {
        $local = $this->createFullAccount('local');

        $visibility = app(RemoteNoteUpserter::class)->visibilityFromAudience([
            'to' => [$local->actor->uri],
            'cc' => ['https://remoto.example/users/alice'],
        ]);

        $this->assertSame(Post::VISIBILITY_DIRECT, $visibility);
    }

    public function test_an_inbound_direct_message_is_stored_and_linked_to_a_conversation(): void
    {
        $local = $this->createFullAccount('local');
        $remote = $this->createRemoteActor('sender');

        $noteUri = 'https://remoto.example/users/sender/statuses/99';
        $activity = [
            'id' => 'https://remoto.example/activities/'.uniqid(),
            'type' => 'Create',
            'actor' => $remote->uri,
            'to' => [$local->actor->uri],
            'cc' => [$remote->uri],
            'object' => [
                'id' => $noteUri,
                'type' => 'Note',
                'attributedTo' => $remote->uri,
                'to' => [$local->actor->uri],
                'cc' => [$remote->uri],
                'content' => '<p>Ciao da Mastodon</p>',
                'published' => now()->toAtomString(),
            ],
        ];

        $item = InboxItem::query()->create([
            'is_shared' => false,
            'remote_activity_uri' => $activity['id'],
            'activity_type' => 'Create',
            'actor_uri' => $remote->uri,
            'payload' => json_encode($activity, JSON_THROW_ON_ERROR),
            'signature_valid' => true,
            'status' => InboxItem::STATUS_PENDING,
            'received_at' => now(),
        ]);

        $status = app(InboxActivityProcessor::class)->process($item);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);

        $post = Post::query()->where('uri', $noteUri)->first();
        $this->assertNotNull($post);
        $this->assertSame(Post::VISIBILITY_DIRECT, $post->visibility);
        $this->assertSame('Ciao da Mastodon', $post->body);
        $this->assertNotNull($post->conversation_id);

        $conversation = Conversation::query()->find($post->conversation_id);
        $this->assertNotNull($conversation);
        $this->assertTrue($conversation->involves($local->actor));
        $this->assertTrue($conversation->involves($remote));

        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $local->id,
            'type' => Notification::TYPE_DIRECT_MESSAGE,
            'notifiable_id' => $post->id,
        ]);
    }

    public function test_inbound_direct_message_fetches_content_when_note_is_a_stub(): void
    {
        $local = $this->createFullAccount('local');
        $remote = $this->createRemoteActor('sender', 'remoto.example');

        $noteUri = 'https://remoto.example/users/sender/statuses/99';

        Http::fake([
            $noteUri => Http::response([
                'id' => $noteUri,
                'type' => 'Note',
                'attributedTo' => $remote->uri,
                'content' => '<p>Messaggio fetchato</p>',
                'published' => now()->toAtomString(),
                'to' => [$local->actor->uri],
                'cc' => [$remote->uri],
            ], 200, ['Content-Type' => 'application/activity+json']),
        ]);

        $activity = [
            'id' => 'https://remoto.example/activities/'.uniqid(),
            'type' => 'Create',
            'actor' => $remote->uri,
            'to' => [$local->actor->uri],
            'cc' => [$remote->uri],
            'object' => [
                'id' => $noteUri,
                'type' => 'Note',
                'attributedTo' => $remote->uri,
                'to' => [$local->actor->uri],
                'cc' => [$remote->uri],
                'published' => now()->toAtomString(),
            ],
        ];

        $item = InboxItem::query()->create([
            'is_shared' => false,
            'remote_activity_uri' => $activity['id'],
            'activity_type' => 'Create',
            'actor_uri' => $remote->uri,
            'payload' => json_encode($activity, JSON_THROW_ON_ERROR),
            'signature_valid' => true,
            'status' => InboxItem::STATUS_PENDING,
            'received_at' => now(),
        ]);

        $status = app(InboxActivityProcessor::class)->process($item);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);

        $post = Post::query()->where('uri', $noteUri)->first();
        $this->assertNotNull($post);
        $this->assertSame('Messaggio fetchato', $post->body);
    }

    public function test_inbound_direct_message_merges_activity_audience_when_note_has_none(): void
    {
        $local = $this->createFullAccount('local');
        $remote = $this->createRemoteActor('sender', 'remoto.example');

        $noteUri = 'https://remoto.example/users/sender/statuses/100';

        $activity = [
            'id' => 'https://remoto.example/activities/'.uniqid(),
            'type' => 'Create',
            'actor' => $remote->uri,
            'to' => [$local->actor->uri],
            'cc' => [$remote->uri],
            'object' => [
                'id' => $noteUri,
                'type' => 'Note',
                'attributedTo' => $remote->uri,
                'content' => '<p>Audience dall attivita</p>',
                'published' => now()->toAtomString(),
            ],
        ];

        $item = InboxItem::query()->create([
            'is_shared' => false,
            'remote_activity_uri' => $activity['id'],
            'activity_type' => 'Create',
            'actor_uri' => $remote->uri,
            'payload' => json_encode($activity, JSON_THROW_ON_ERROR),
            'signature_valid' => true,
            'status' => InboxItem::STATUS_PENDING,
            'received_at' => now(),
        ]);

        $status = app(InboxActivityProcessor::class)->process($item);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);

        $post = Post::query()->where('uri', $noteUri)->first();
        $this->assertNotNull($post);
        $this->assertSame(Post::VISIBILITY_DIRECT, $post->visibility);
        $this->assertSame('Audience dall attivita', $post->body);
    }

    public function test_inbound_direct_message_stub_without_fetchable_content_does_not_store_note_uri_as_body(): void
    {
        $local = $this->createFullAccount('local');
        $remote = $this->createRemoteActor('sender', 'remoto.example');

        $noteUri = 'https://remoto.example/users/sender/statuses/101';

        Http::fake([
            $noteUri => Http::response('', 404),
        ]);

        $activity = [
            'id' => 'https://remoto.example/activities/'.uniqid(),
            'type' => 'Create',
            'actor' => $remote->uri,
            'to' => [$local->actor->uri],
            'cc' => [$remote->uri],
            'object' => [
                'id' => $noteUri,
                'type' => 'Note',
                'attributedTo' => $remote->uri,
                'to' => [$local->actor->uri],
                'cc' => [$remote->uri],
                'published' => now()->toAtomString(),
            ],
        ];

        $item = InboxItem::query()->create([
            'is_shared' => false,
            'remote_activity_uri' => $activity['id'],
            'activity_type' => 'Create',
            'actor_uri' => $remote->uri,
            'payload' => json_encode($activity, JSON_THROW_ON_ERROR),
            'signature_valid' => true,
            'status' => InboxItem::STATUS_PENDING,
            'received_at' => now(),
        ]);

        $status = app(InboxActivityProcessor::class)->process($item);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);

        $post = Post::query()->where('uri', $noteUri)->first();
        $this->assertNotNull($post);
        $this->assertSame('', $post->body);
    }
}
