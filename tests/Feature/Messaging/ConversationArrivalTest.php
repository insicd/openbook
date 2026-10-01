<?php

namespace Tests\Feature\Messaging;

use App\Application\Services\ConversationReadTracker;
use App\Application\Services\DirectMessageLinker;
use App\Application\Services\MessageComposer;
use App\Domain\Messaging\Conversation;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class ConversationArrivalTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_deleting_an_unread_message_invalidates_the_shared_feed_without_affecting_other_users(): void
    {
        Queue::fake();
        $viewer = $this->createFullAccount('viewer');
        $sender = $this->createFullAccount('sender');
        $other = $this->createFullAccount('other');
        $message = app(MessageComposer::class)->send($sender->actor, $viewer->actor, 'Da eliminare');
        $feed = $this->actingAs($viewer)->getJson(route('notifications.feed'));
        $feed->assertOk()->assertJsonPath('unread_conversations_count', 1);
        $otherRevision = $other->fresh()->notifications_revision;

        $this->actingAs($sender)->delete(route('posts.destroy', $message))->assertRedirect();
        $this->actingAs($viewer)->withHeader('If-None-Match', $feed->headers->get('ETag'))
            ->getJson(route('notifications.feed'))->assertOk()->assertJsonPath('unread_conversations_count', 0);
        $this->assertSame($otherRevision, $other->fresh()->notifications_revision);
    }

    public function test_shared_notification_feed_tracks_conversations_independently_of_notification_reads(): void
    {
        Queue::fake();
        $viewer = $this->createFullAccount('viewer');
        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');
        $first = app(MessageComposer::class)->send($alice->actor, $viewer->actor, 'Primo');
        app(MessageComposer::class)->send($alice->actor, $viewer->actor, 'Secondo');
        app(MessageComposer::class)->send($bob->actor, $viewer->actor, 'Altra chat');
        app(MessageComposer::class)->send($alice->actor, $bob->actor, 'Non del viewer');

        $feed = $this->actingAs($viewer)->getJson(route('notifications.feed'));
        $feed->assertOk()->assertJsonPath('unread_count', 2)->assertJsonPath('unread_conversations_count', 2);
        $this->withHeader('If-None-Match', $feed->headers->get('ETag'))
            ->getJson(route('notifications.feed'))->assertStatus(304);

        $this->postJson(route('notifications.read'))->assertOk();
        $feed = $this->getJson(route('notifications.feed'));
        $feed->assertOk()->assertJsonPath('unread_count', 0)->assertJsonPath('unread_conversations_count', 2);
        $etag = $feed->headers->get('ETag');

        $this->get(route('messages.show', $first->conversation_id))->assertOk();
        $feed = $this->withHeader('If-None-Match', $etag)->getJson(route('notifications.feed'));
        $feed->assertOk()->assertJsonPath('unread_count', 0)->assertJsonPath('unread_conversations_count', 1);
        $this->assertNotSame($etag, $feed->headers->get('ETag'));
        $this->withHeader('If-None-Match', $feed->headers->get('ETag'))
            ->getJson(route('notifications.feed'))->assertStatus(304);

        $this->travel(1)->minute();
        app(MessageComposer::class)->send($alice->actor, $viewer->actor, 'Nuovo');
        $feed = $this->getJson(route('notifications.feed'));
        $feed->assertOk()->assertJsonPath('unread_count', 1)->assertJsonPath('unread_conversations_count', 2);
    }

    public function test_polling_does_not_mark_messages_beyond_the_response_limit_as_read_or_skip_them(): void
    {
        Queue::fake();
        $viewer = $this->createFullAccount('viewer');
        $sender = $this->createFullAccount('sender');
        $first = app(MessageComposer::class)->send($sender->actor, $viewer->actor, 'Primo');
        $this->actingAs($viewer)->get(route('messages.show', $first->conversation_id))->assertOk();
        $this->travel(1)->minute();
        $newMessages = [];
        for ($i = 0; $i < 51; $i++) {
            $newMessages[] = app(MessageComposer::class)->send($sender->actor, $viewer->actor, 'Nuovo '.$i);
        }

        $response = $this->getJson(route('messages.feed', $first->conversation_id).'?after='.$first->id);
        $response->assertOk()->assertJsonCount(50, 'messages');
        $this->assertSame(1, app(ConversationReadTracker::class)->unreadCountFor($viewer->actor));
        $this->assertSame(1, Notification::query()->where('recipient_id', $viewer->id)->whereNull('read_at')->count());

        $this->withHeader('If-None-Match', $response->headers->get('ETag'))
            ->getJson(route('messages.feed', $first->conversation_id).'?after='.$response->json('messages.49.id'))
            ->assertOk()->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.id', $newMessages[50]->id);
        $this->assertSame(0, app(ConversationReadTracker::class)->unreadCountFor($viewer->actor));
    }

    public function test_migration_preserves_existing_reads_and_uses_local_arrival_for_activity(): void
    {
        Queue::fake();
        $viewer = $this->createFullAccount('viewer');
        $sender = $this->createFullAccount('sender');
        $first = app(MessageComposer::class)->send($sender->actor, $viewer->actor, 'Primo');
        $oldReadAt = $first->created_at;
        $this->travel(1)->minute();
        $second = app(MessageComposer::class)->send($sender->actor, $viewer->actor, 'Secondo');
        $second->update(['published_at' => now()->subHour()]);
        DB::table('conversation_reads')->insert([
            'conversation_id' => $first->conversation_id, 'user_id' => $viewer->id,
            'last_read_at' => $oldReadAt, 'last_read_message_id' => null,
        ]);

        $migration = require database_path('migrations/2026_10_01_000001_use_arrival_order_for_conversation_reads.php');
        $migration->down();
        $this->assertTrue(Schema::hasIndex('posts', 'posts_conversation_id_index'));
        $this->assertTrue(Schema::hasIndex('posts', 'posts_community_id_index'));
        $this->assertFalse(Schema::hasIndex('posts', 'posts_conversation_arrival_index'));
        $revision = $viewer->fresh()->notifications_revision;
        $migration->up();
        $this->assertSame($revision + 1, $viewer->fresh()->notifications_revision);
        $this->assertFalse(Schema::hasIndex('posts', 'posts_conversation_id_index'));
        $this->assertFalse(Schema::hasIndex('posts', 'posts_community_id_index'));
        $this->assertTrue(Schema::hasIndex('posts', 'posts_conversation_arrival_index'));

        $this->assertDatabaseHas('conversation_reads', [
            'conversation_id' => $first->conversation_id, 'user_id' => $viewer->id, 'last_read_message_id' => $first->id,
        ]);
        $this->assertSame(1, app(ConversationReadTracker::class)->unreadCountFor($viewer->actor));
        $this->assertSame($second->created_at->timestamp, Conversation::findOrFail($first->conversation_id)->last_message_at->timestamp);
    }

    public function test_reading_chat_a_keeps_new_message_in_chat_b_unread_in_the_list(): void
    {
        Queue::fake();
        $viewer = $this->createFullAccount('viewer');
        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');
        $a = app(MessageComposer::class)->send($alice->actor, $viewer->actor, 'Chat A');
        $b = app(MessageComposer::class)->send($bob->actor, $viewer->actor, 'Chat B');
        $this->actingAs($viewer)->get(route('messages.show', $b->conversation_id))->assertOk();
        $this->get(route('messages.show', $a->conversation_id))->assertOk();
        $this->travel(1)->minute();
        app(MessageComposer::class)->send($bob->actor, $viewer->actor, 'Nuovo B');
        app(MessageComposer::class)->send($alice->actor, $viewer->actor, 'Nuovo A');
        $this->getJson(route('messages.feed', $a->conversation_id).'?after='.$a->id)->assertOk();
        $this->get(route('messages.index'))->assertOk()->assertViewHas('unreadFlags', fn ($flags) => $flags[$b->conversation_id] && ! $flags[$a->conversation_id])->assertSee('ob-message-row--unread');
        $this->assertSame(1, app(ConversationReadTracker::class)->unreadCountFor($viewer->actor));
    }

    public function test_a_second_message_received_in_the_same_second_as_reading_chat_b_is_unread(): void
    {
        Queue::fake();
        $this->travelTo(now()->startOfSecond());
        $viewer = $this->createFullAccount('viewer');
        $bob = $this->createFullAccount('bob');
        $b = app(MessageComposer::class)->send($bob->actor, $viewer->actor, 'Primo B');
        $this->actingAs($viewer)->get(route('messages.show', $b->conversation_id))->assertOk();
        app(MessageComposer::class)->send($bob->actor, $viewer->actor, 'Secondo B');
        $this->get(route('messages.index'))->assertOk()->assertViewHas('unreadFlags', fn ($flags) => $flags[$b->conversation_id]);
    }

    public function test_a_federated_message_published_before_the_last_read_but_imported_after_is_unread(): void
    {
        Queue::fake();
        $viewer = $this->createFullAccount('viewer');
        $remote = $this->createRemoteActor('sender');
        $note = ['to' => [$viewer->actor->uri], 'cc' => []];
        $first = Post::query()->create(['actor_id' => $remote->id, 'body' => 'Primo remoto', 'visibility' => 'direct', 'status' => 'published', 'published_at' => now()->subMinutes(5)]);
        app(DirectMessageLinker::class)->link($first, $remote, $note, true);
        $this->actingAs($viewer)->get(route('messages.show', $first->conversation_id))->assertOk();
        $this->travel(1)->minute();
        $delayed = Post::query()->create(['actor_id' => $remote->id, 'body' => 'Remoto importato in ritardo', 'visibility' => 'direct', 'status' => 'published', 'published_at' => now()->subMinutes(2)]);
        app(DirectMessageLinker::class)->link($delayed, $remote, $note, true);
        $this->get(route('messages.index'))->assertOk()->assertViewHas('unreadFlags', fn ($flags) => $flags[$first->conversation_id]);
        $feed = $this->getJson(route('messages.feed', $first->conversation_id).'?after='.$first->id);
        $feed->assertOk()->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.id', $delayed->id)
            ->assertJsonPath('messages.0.published_at', $delayed->published_at->toIso8601String());
        $this->get(route('messages.show', $first->conversation_id))->assertOk()
            ->assertSeeInOrder(['Primo remoto', 'Remoto importato in ritardo'])
            ->assertSee($delayed->published_at->format('d/m/Y H:i'));
        $this->assertSame(0, app(ConversationReadTracker::class)->unreadCountFor($viewer->actor));
        $this->assertSame($delayed->created_at->timestamp, Conversation::findOrFail($first->conversation_id)->last_message_at->timestamp);
    }
}
