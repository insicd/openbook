<?php

namespace Tests\Feature\Events;

use App\Application\Services\EventCommentComposer;
use App\Application\Services\ReactionManager;
use App\Domain\Events\Event;
use App\Domain\Events\EventComment;
use App\Domain\Notifications\Notification;
use App\Federation\Actors\Actor;
use App\Federation\Serialization\ActivitySerializer;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class EventCommentTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_local_comment_is_stored_notifies_the_owner_and_is_rendered(): void
    {
        Queue::fake();
        $owner = $this->createFullAccount('eventcommentowner');
        $commenter = $this->createFullAccount('eventcommenter');
        $event = $this->event($owner->actor);

        $this->actingAs($commenter)->post(route('event-comments.store', $event), [
            'body' => 'Che bellissimo evento!',
        ])->assertRedirect();

        $comment = EventComment::query()->sole();
        $this->assertSame($event->id, $comment->event_id);
        $this->assertSame($commenter->actor->id, $comment->actor_id);
        $this->assertSame(route('event-comments.show', $comment), $comment->uri);
        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $owner->id,
            'actor_id' => $commenter->actor->id,
            'type' => Notification::TYPE_COMMENT,
            'notifiable_type' => 'event_comment',
            'notifiable_id' => $comment->id,
        ]);
        $this->actingAs($owner)
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('Che bellissimo evento!', false);
        Queue::assertNotPushed(DeliverActivityJob::class);
    }

    public function test_reply_serializes_as_a_note_addressed_to_the_parent_author(): void
    {
        Queue::fake();
        $owner = $this->createFullAccount('replyeventowner');
        $replier = $this->createFullAccount('eventreplier');
        $remote = $this->createRemoteActor('remotecommenter', 'comments.example');
        $event = $this->event($owner->actor);
        $parent = $this->remoteComment($event, $remote);

        $this->actingAs($replier)->post(route('event-comments.store', $event), [
            'body' => 'Confermo, ci sarò.',
            'parent_comment_id' => $parent->id,
        ])->assertRedirect();

        $reply = EventComment::query()->where('actor_id', $replier->actor->id)->sole();
        $note = ActivitySerializer::create($reply->fresh(['actor.endpoints', 'event.actor.endpoints', 'parent.actor', 'mentions.actor', 'media']));
        $this->assertSame('Create', $note['type']);
        $this->assertSame('Note', $note['object']['type']);
        $this->assertSame($parent->uri, $note['object']['inReplyTo']);
        $this->assertContains($remote->uri, $note['object']['cc']);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Create'
            && ($job->activity['object']['inReplyTo'] ?? null) === $parent->uri
        );
    }

    public function test_comment_can_be_liked_and_unliked(): void
    {
        Queue::fake();
        $owner = $this->createFullAccount('likeeventowner');
        $commenter = $this->createFullAccount('likeeventcommenter');
        $liker = $this->createFullAccount('eventcommentliker');
        $event = $this->event($owner->actor);
        $comment = app(EventCommentComposer::class)->compose($commenter->actor, $event, 'Un commento simpatico.');

        $this->actingAs($liker)->post(route('event-comments.like', $comment))->assertRedirect();
        $this->assertSame(1, $comment->fresh()->likes_count);
        $this->assertDatabaseHas('likes', [
            'actor_id' => $liker->actor->id,
            'likeable_type' => 'event_comment',
            'likeable_id' => $comment->id,
        ]);

        $this->actingAs($liker)->delete(route('event-comments.unlike', $comment))->assertRedirect();
        $this->assertSame(0, $comment->fresh()->likes_count);
        $this->assertDatabaseMissing('likes', ['likeable_id' => $comment->id]);
    }

    public function test_local_like_of_remote_event_comment_is_federated(): void
    {
        Queue::fake();
        $liker = $this->createFullAccount('remotecmtliker');
        $remote = $this->createRemoteActor('remotecommentlike', 'comments.example');
        $event = $this->event($remote);
        $comment = $this->remoteComment($event, $remote);

        app(ReactionManager::class)->like($liker->actor, $comment);

        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Like'
            && $job->activity['object'] === $comment->uri
        );
    }

    public function test_author_can_delete_comment_without_removing_its_replies(): void
    {
        Queue::fake();
        $owner = $this->createFullAccount('deleteeventowner');
        $commenter = $this->createFullAccount('deleteeventcommenter');
        $replier = $this->createFullAccount('deleteeventreplier');
        $event = $this->event($owner->actor);
        $parent = app(EventCommentComposer::class)->compose($commenter->actor, $event, 'Commento da cancellare.');
        $reply = app(EventCommentComposer::class)->compose($replier->actor, $event, 'Risposta che deve restare.', $parent);

        $this->actingAs($owner)->delete(route('event-comments.destroy', $parent))->assertForbidden();
        $this->actingAs($commenter)->delete(route('event-comments.destroy', $parent))->assertRedirect();

        $this->assertSame(EventComment::STATUS_DELETED, $parent->fresh()->status);
        $this->assertSame(EventComment::STATUS_PUBLISHED, $reply->fresh()->status);
        $this->actingAs($owner)
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertDontSee('Commento da cancellare.', false)
            ->assertSee('Risposta che deve restare.', false);

        $tombstone = $this->getJson(route('event-comments.show', $parent), [
            'Accept' => 'application/activity+json',
        ])->assertOk()->json();
        $this->assertSame('Tombstone', $tombstone['type']);
        $this->assertSame($parent->uri, $tombstone['id']);
    }

    public function test_comments_are_rejected_for_closed_or_invisible_events(): void
    {
        $owner = $this->createFullAccount('closedeventowner');
        $commenter = $this->createFullAccount('closedeventcommenter');
        $closed = $this->event($owner->actor, [
            'start_at' => now()->subDays(2),
            'end_at' => now()->subDay(),
        ]);
        $direct = $this->event($owner->actor, [
            'uri' => route('events.show', fake()->uuid()),
            'visibility' => Event::VISIBILITY_DIRECT,
        ]);

        $this->actingAs($commenter)->post(route('event-comments.store', $closed), [
            'body' => 'Troppo tardi.',
        ])->assertNotFound();
        $this->actingAs($commenter)->post(route('event-comments.store', $direct), [
            'body' => 'Non autorizzato.',
        ])->assertNotFound();
        $this->assertDatabaseCount('event_comments', 0);
    }

    /** @param array<string, mixed> $attributes */
    private function event(Actor $actor, array $attributes = []): Event
    {
        $event = new Event;
        $event->id = $event->newUniqueId();
        $event->forceFill([
            'actor_id' => $actor->id,
            'uri' => route('events.show', $event->id),
            'url' => route('events.show', $event->id),
            'name' => 'Evento con commenti',
            'visibility' => Event::VISIBILITY_PUBLIC,
            'status' => Event::STATUS_SCHEDULED,
            'start_at' => now()->addDay(),
            'published_at' => now(),
            ...$attributes,
        ])->save();

        return $event->fresh('actor');
    }

    private function remoteComment(Event $event, Actor $actor): EventComment
    {
        return EventComment::query()->create([
            'event_id' => $event->id,
            'actor_id' => $actor->id,
            'uri' => $actor->uri.'/statuses/'.fake()->uuid(),
            'body' => 'Commento remoto.',
            'status' => EventComment::STATUS_PUBLISHED,
        ]);
    }
}
