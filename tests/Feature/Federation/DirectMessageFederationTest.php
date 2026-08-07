<?php

namespace Tests\Feature\Federation;

use App\Domain\Messaging\Conversation;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Mention;
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

    public function test_inbound_direct_message_reply_stays_in_the_conversation_thread(): void
    {
        $local = $this->createFullAccount('local');
        $remote = $this->createRemoteActor('sender', 'remoto.example');

        $firstUri = 'https://remoto.example/users/sender/statuses/1';
        $firstActivity = [
            'id' => 'https://remoto.example/activities/first',
            'type' => 'Create',
            'actor' => $remote->uri,
            'to' => [$local->actor->uri],
            'cc' => [$remote->uri],
            'object' => [
                'id' => $firstUri,
                'type' => 'Note',
                'attributedTo' => $remote->uri,
                'to' => [$local->actor->uri],
                'cc' => [$remote->uri],
                'content' => '<p>Primo messaggio</p>',
                'published' => now()->subMinute()->toAtomString(),
            ],
        ];

        $firstItem = InboxItem::query()->create([
            'is_shared' => false,
            'remote_activity_uri' => $firstActivity['id'],
            'activity_type' => 'Create',
            'actor_uri' => $remote->uri,
            'payload' => json_encode($firstActivity, JSON_THROW_ON_ERROR),
            'signature_valid' => true,
            'status' => InboxItem::STATUS_PENDING,
            'received_at' => now(),
        ]);

        app(InboxActivityProcessor::class)->process($firstItem);

        $firstPost = Post::query()->where('uri', $firstUri)->firstOrFail();
        $conversationId = $firstPost->conversation_id;
        $this->assertNotNull($conversationId);

        $replyUri = 'https://remoto.example/users/sender/statuses/2';
        $replyActivity = [
            'id' => 'https://remoto.example/activities/reply',
            'type' => 'Create',
            'actor' => $remote->uri,
            'to' => [$local->actor->uri],
            'cc' => [$remote->uri],
            'object' => [
                'id' => $replyUri,
                'type' => 'Note',
                'attributedTo' => $remote->uri,
                'inReplyTo' => $firstUri,
                'to' => [$local->actor->uri],
                'cc' => [$remote->uri],
                'content' => '<p>Risposta nel thread</p>',
                'published' => now()->toAtomString(),
            ],
        ];

        $replyItem = InboxItem::query()->create([
            'is_shared' => false,
            'remote_activity_uri' => $replyActivity['id'],
            'activity_type' => 'Create',
            'actor_uri' => $remote->uri,
            'payload' => json_encode($replyActivity, JSON_THROW_ON_ERROR),
            'signature_valid' => true,
            'status' => InboxItem::STATUS_PENDING,
            'received_at' => now(),
        ]);

        $status = app(InboxActivityProcessor::class)->process($replyItem);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertDatabaseMissing('comments', ['uri' => $replyUri]);

        $replyPost = Post::query()->where('uri', $replyUri)->first();
        $this->assertNotNull($replyPost);
        $this->assertSame(Post::VISIBILITY_DIRECT, $replyPost->visibility);
        $this->assertSame($conversationId, $replyPost->conversation_id);
        $this->assertSame('Risposta nel thread', $replyPost->body);
    }

    public function test_inbound_direct_message_reply_to_local_outbound_message_stays_in_thread(): void
    {
        $local = $this->createFullAccount('local');
        $remote = $this->createRemoteActor('sender', 'remoto.example');
        $conversation = Conversation::query()->create([
            'participant_low_id' => Conversation::orderParticipantIds($local->actor->id, $remote->id)[0],
            'participant_high_id' => Conversation::orderParticipantIds($local->actor->id, $remote->id)[1],
            'last_message_at' => now()->subMinute(),
        ]);

        $outbound = Post::query()->create([
            'actor_id' => $local->actor->id,
            'body' => 'Ciao da OpenBook',
            'visibility' => Post::VISIBILITY_DIRECT,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subMinute(),
            'conversation_id' => $conversation->id,
        ]);
        $outboundUri = url('/posts/'.$outbound->id);
        $outbound->update(['uri' => $outboundUri]);

        Mention::query()->create([
            'mentionable_type' => $outbound->getMorphClass(),
            'mentionable_id' => $outbound->id,
            'actor_id' => $remote->id,
        ]);

        $replyUri = 'https://remoto.example/users/sender/statuses/reply-local';
        $replyActivity = [
            'id' => 'https://remoto.example/activities/reply-local',
            'type' => 'Create',
            'actor' => $remote->uri,
            'to' => [$local->actor->uri],
            'cc' => [$remote->uri],
            'object' => [
                'id' => $replyUri,
                'type' => 'Note',
                'attributedTo' => $remote->uri,
                'inReplyTo' => $outboundUri,
                'to' => [$local->actor->uri],
                'cc' => [$remote->uri],
                'content' => '<p>Risposta al tuo messaggio</p>',
                'published' => now()->toAtomString(),
            ],
        ];

        $replyItem = InboxItem::query()->create([
            'is_shared' => false,
            'remote_activity_uri' => $replyActivity['id'],
            'activity_type' => 'Create',
            'actor_uri' => $remote->uri,
            'payload' => json_encode($replyActivity, JSON_THROW_ON_ERROR),
            'signature_valid' => true,
            'status' => InboxItem::STATUS_PENDING,
            'received_at' => now(),
        ]);

        $status = app(InboxActivityProcessor::class)->process($replyItem);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertDatabaseMissing('comments', ['uri' => $replyUri]);

        $replyPost = Post::query()->where('uri', $replyUri)->first();
        $this->assertNotNull($replyPost);
        $this->assertSame($conversation->id, $replyPost->conversation_id);
        $this->assertSame('Risposta al tuo messaggio', $replyPost->body);
    }

    public function test_mastodon_direct_message_reply_with_direct_message_flag_stays_in_thread(): void
    {
        $local = $this->createFullAccount('local');
        $remote = $this->createRemoteActor('nuke', 'poliversity.it');
        $conversation = Conversation::query()->create([
            'participant_low_id' => Conversation::orderParticipantIds($local->actor->id, $remote->id)[0],
            'participant_high_id' => Conversation::orderParticipantIds($local->actor->id, $remote->id)[1],
            'last_message_at' => now()->subHour(),
        ]);

        $outbound = Post::query()->create([
            'actor_id' => $local->actor->id,
            'body' => 'Messaggio iniziale OpenBook',
            'visibility' => Post::VISIBILITY_DIRECT,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subHour(),
            'conversation_id' => $conversation->id,
        ]);
        $outboundUri = url('/posts/'.$outbound->id);
        $outbound->update(['uri' => $outboundUri]);

        Mention::query()->create([
            'mentionable_type' => $outbound->getMorphClass(),
            'mentionable_id' => $outbound->id,
            'actor_id' => $remote->id,
        ]);

        $replyUri = 'https://poliversity.it/users/nuke/statuses/117054655877146352';
        $activity = [
            'id' => $replyUri.'/activity',
            'type' => 'Create',
            'actor' => $remote->uri,
            'published' => now()->toAtomString(),
            'to' => [$local->actor->uri],
            'cc' => [],
            'object' => [
                'id' => $replyUri,
                'type' => 'Note',
                'summary' => null,
                'inReplyTo' => $outboundUri,
                'inReplyToAtomUri' => $outboundUri,
                'published' => now()->toAtomString(),
                'attributedTo' => $remote->uri,
                'to' => [$local->actor->uri],
                'cc' => [],
                'sensitive' => false,
                'directMessage' => true,
                'content' => '<p><span class="h-card">@local</span> si si vedo tutto</p>',
                'contentMap' => [
                    'it' => '<p><span class="h-card">@local</span> si si vedo tutto</p>',
                ],
                'tag' => [[
                    'type' => 'Mention',
                    'href' => $local->actor->uri,
                    'name' => '@local@'.config('openbook.domain'),
                ]],
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
        $this->assertDatabaseMissing('comments', ['uri' => $replyUri]);

        $replyPost = Post::query()->where('uri', $replyUri)->first();
        $this->assertNotNull($replyPost);
        $this->assertSame(Post::VISIBILITY_DIRECT, $replyPost->visibility);
        $this->assertSame($conversation->id, $replyPost->conversation_id);
        $this->assertStringContainsString('si si vedo tutto', $replyPost->body);
    }

    public function test_reply_to_a_conversation_message_is_not_stored_as_comment_when_parent_visibility_is_wrong(): void
    {
        $local = $this->createFullAccount('local');
        $remote = $this->createRemoteActor('sender', 'remoto.example');
        $conversation = Conversation::query()->create([
            'participant_low_id' => Conversation::orderParticipantIds($local->actor->id, $remote->id)[0],
            'participant_high_id' => Conversation::orderParticipantIds($local->actor->id, $remote->id)[1],
            'last_message_at' => now()->subMinutes(10),
        ]);

        $parentUri = 'https://remoto.example/users/sender/statuses/parent';
        $parent = Post::query()->create([
            'uri' => $parentUri,
            'actor_id' => $remote->id,
            'body' => 'Primo messaggio',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subMinutes(10),
            'conversation_id' => $conversation->id,
        ]);

        $replyUri = 'https://remoto.example/users/sender/statuses/reply';
        $activity = [
            'id' => 'https://remoto.example/activities/reply-wrong-vis',
            'type' => 'Create',
            'actor' => $remote->uri,
            'to' => [$local->actor->uri],
            'cc' => [],
            'object' => [
                'id' => $replyUri,
                'type' => 'Note',
                'attributedTo' => $remote->uri,
                'inReplyTo' => $parentUri,
                'directMessage' => true,
                'content' => '<p>Risposta canalizzata</p>',
                'published' => now()->toAtomString(),
                'to' => [$local->actor->uri],
                'cc' => [],
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

        app(InboxActivityProcessor::class)->process($item);

        $this->assertDatabaseMissing('comments', ['uri' => $replyUri]);
        $reply = Post::query()->where('uri', $replyUri)->first();
        $this->assertNotNull($reply);
        $this->assertSame($conversation->id, $reply->conversation_id);
        $this->assertSame('Risposta canalizzata', $reply->body);
    }
}
