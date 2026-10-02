<?php

namespace Tests\Feature\Messaging;

use App\Application\Services\MessageComposer;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class DirectMessageNotificationMigrationTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_cleanup_keeps_the_latest_notification_and_preserves_unread_state_per_recipient_and_conversation(): void
    {
        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');
        $carol = $this->createFullAccount('carol');

        $first = app(MessageComposer::class)->send($alice->actor, $bob->actor, 'Primo');
        $this->travel(1)->minute();
        $latestPost = app(MessageComposer::class)->send($alice->actor, $bob->actor, 'Ultimo');
        $latest = Notification::query()->where('recipient_id', $bob->id)->firstOrFail();
        $latest->forceFill(['read_at' => now()])->save();

        $oldUnread = Notification::query()->create([
            'recipient_id' => $bob->id,
            'actor_id' => $alice->actor->id,
            'type' => Notification::TYPE_DIRECT_MESSAGE,
            'notifiable_type' => $first->getMorphClass(),
            'notifiable_id' => $first->id,
        ]);
        $oldUnread->forceFill(['created_at' => now()->subMinute()])->save();

        $reply = app(MessageComposer::class)->send($bob->actor, $alice->actor, 'Risposta');
        $aliceLatest = Notification::query()->where('recipient_id', $alice->id)->firstOrFail();
        $aliceLatest->forceFill(['read_at' => now()])->save();
        $aliceOld = Notification::query()->create([
            'recipient_id' => $alice->id,
            'actor_id' => $bob->actor->id,
            'type' => Notification::TYPE_DIRECT_MESSAGE,
            'notifiable_type' => $reply->getMorphClass(),
            'notifiable_id' => $reply->id,
        ]);
        $aliceOld->forceFill(['created_at' => now()->subMinute(), 'read_at' => now()])->save();
        $oldUnread->forceFill(['read_at' => null])->save();

        app(MessageComposer::class)->send($carol->actor, $bob->actor, 'Altra conversazione');
        $unrelated = Notification::query()->create([
            'recipient_id' => $bob->id,
            'type' => Notification::TYPE_MENTION,
            'notifiable_type' => $first->getMorphClass(),
            'notifiable_id' => $first->id,
        ]);
        $orphan = Notification::query()->create([
            'recipient_id' => $bob->id,
            'type' => Notification::TYPE_DIRECT_MESSAGE,
            'notifiable_type' => $first->getMorphClass(),
            'notifiable_id' => fake()->uuid(),
        ]);
        $unlinkedPost = Post::query()->create([
            'actor_id' => $alice->actor->id,
            'body' => 'Senza conversazione',
            'visibility' => Post::VISIBILITY_DIRECT,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $unlinked = Notification::query()->create([
            'recipient_id' => $bob->id,
            'type' => Notification::TYPE_DIRECT_MESSAGE,
            'notifiable_type' => $unlinkedPost->getMorphClass(),
            'notifiable_id' => $unlinkedPost->id,
        ]);
        $bobRevision = $bob->fresh()->notifications_revision;
        $aliceRevision = $alice->fresh()->notifications_revision;
        $carolRevision = $carol->fresh()->notifications_revision;

        $migration = require database_path('migrations/2026_10_01_000000_deduplicate_direct_message_notifications.php');
        $migration->up();

        $this->assertDatabaseMissing('notifications', ['id' => $oldUnread->id]);
        $this->assertDatabaseMissing('notifications', ['id' => $aliceOld->id]);
        $this->assertDatabaseHas('notifications', ['id' => $latest->id, 'notifiable_id' => $latestPost->id, 'read_at' => null]);
        $this->assertNotNull($aliceLatest->fresh()->read_at);
        $this->assertDatabaseHas('notifications', ['id' => $unrelated->id]);
        $this->assertDatabaseHas('notifications', ['id' => $orphan->id]);
        $this->assertDatabaseHas('notifications', ['id' => $unlinked->id]);
        $this->assertSame(1, Notification::query()->forDirectConversation($bob->id, $first->conversation_id)->count());
        $this->assertSame(1, Notification::query()->forDirectConversation($alice->id, $first->conversation_id)->count());
        $this->assertSame($bobRevision + 1, $bob->fresh()->notifications_revision);
        $this->assertSame($aliceRevision + 1, $alice->fresh()->notifications_revision);
        $this->assertSame($carolRevision, $carol->fresh()->notifications_revision);

        $migration->up();
        $migration->down();
        $this->assertSame($bobRevision + 1, $bob->fresh()->notifications_revision);
        $this->assertSame(6, Notification::query()->count());
    }
}
