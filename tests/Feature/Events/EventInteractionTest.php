<?php

namespace Tests\Feature\Events;

use App\Domain\Events\Event;
use App\Domain\Events\EventParticipation;
use App\Domain\Notifications\Notification;
use App\Federation\Actors\Actor;
use App\Federation\Inbox\InboxActivityProcessor;
use App\Federation\Inbox\InboxItem;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class EventInteractionTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_interest_in_a_remote_event_is_federated_and_idempotent(): void
    {
        Queue::fake();
        $user = $this->createFullAccount('eventinterest');
        $event = $this->event($this->remoteActor());

        $this->actingAs($user)->post(route('events.interest', $event))->assertRedirect();
        $this->actingAs($user)->post(route('events.interest', $event))->assertRedirect();

        $this->assertDatabaseHas('likes', [
            'actor_id' => $user->actor->id,
            'likeable_type' => 'event',
            'likeable_id' => $event->id,
        ]);
        $this->assertSame(1, $event->fresh()->likes_count);
        Queue::assertPushed(DeliverActivityJob::class, 1);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Like' && $job->activity['object'] === $event->uri
        );
    }

    public function test_join_is_sent_with_a_mobilizon_compatible_shape(): void
    {
        Queue::fake();
        $user = $this->createFullAccount('eventjoin');
        $event = $this->event($this->remoteActor(), ['join_mode' => 'restricted']);

        $this->actingAs($user)->post(route('events.join', $event))->assertRedirect();

        $participation = EventParticipation::query()->sole();
        $this->assertSame(EventParticipation::STATUS_PENDING, $participation->status);
        $this->assertNotNull($participation->activity_uri);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Join'
            && $job->activity['id'] === $participation->activity_uri
            && $job->activity['actor'] === $user->actor->uri
            && $job->activity['object'] === $event->uri
            && ($job->activity['to'][0] ?? null) === $event->actor->uri
        );
    }

    public function test_accept_of_an_outgoing_join_updates_it_and_notifies_the_user(): void
    {
        Queue::fake();
        $user = $this->createFullAccount('eventaccepted');
        $remote = $this->remoteActor();
        $event = $this->event($remote, ['join_mode' => 'free']);
        $this->actingAs($user)->post(route('events.join', $event));
        $participation = EventParticipation::query()->sole();

        $status = $this->process([
            'id' => $remote->uri.'/activities/accept-event',
            'type' => 'Accept',
            'actor' => $remote->uri,
            'object' => [
                'id' => $participation->activity_uri,
                'type' => 'Join',
                'actor' => $user->actor->uri,
                'object' => $event->uri,
            ],
        ], $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertSame(EventParticipation::STATUS_ACCEPTED, $participation->fresh()->status);
        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $user->id,
            'actor_id' => $remote->id,
            'type' => Notification::TYPE_EVENT_JOIN_ACCEPTED,
            'notifiable_type' => 'event_participation',
            'notifiable_id' => $participation->id,
        ]);
    }

    public function test_leaving_an_accepted_event_sends_leave_and_removes_the_local_state(): void
    {
        Queue::fake();
        $user = $this->createFullAccount('eventleave');
        $event = $this->event($this->remoteActor(), ['join_mode' => 'free']);
        $participation = EventParticipation::query()->create([
            'event_id' => $event->id,
            'actor_id' => $user->actor->id,
            'status' => EventParticipation::STATUS_ACCEPTED,
            'activity_uri' => url('/activities/event-joins/test-leave'),
        ]);

        $this->actingAs($user)->delete(route('events.leave', $event))->assertRedirect();

        $this->assertModelMissing($participation);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Leave' && $job->activity['object'] === $event->uri
        );
    }

    public function test_reject_updates_the_join_and_a_new_request_returns_it_to_pending(): void
    {
        Queue::fake();
        $user = $this->createFullAccount('eventrejected');
        $remote = $this->remoteActor();
        $event = $this->event($remote, ['join_mode' => 'restricted']);
        $this->actingAs($user)->post(route('events.join', $event));
        $participation = EventParticipation::query()->sole();

        $status = $this->process([
            'id' => $remote->uri.'/activities/reject-event',
            'type' => 'Reject',
            'actor' => $remote->uri,
            'object' => $participation->activity_uri,
        ], $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertSame(EventParticipation::STATUS_REJECTED, $participation->fresh()->status);
        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $user->id,
            'type' => Notification::TYPE_EVENT_JOIN_REJECTED,
        ]);

        $rejectedActivityUri = $participation->fresh()->activity_uri;
        $this->actingAs($user)->post(route('events.join', $event))->assertRedirect();
        $this->assertSame(EventParticipation::STATUS_PENDING, $participation->fresh()->status);
        $this->assertNull($participation->fresh()->responded_at);
        $this->assertNotSame($rejectedActivityUri, $participation->fresh()->activity_uri);
    }

    public function test_cancelling_a_pending_join_sends_undo_join(): void
    {
        Queue::fake();
        $user = $this->createFullAccount('eventundojoin');
        $event = $this->event($this->remoteActor(), ['join_mode' => 'free']);
        $this->actingAs($user)->post(route('events.join', $event));
        Queue::fake();

        $this->actingAs($user)->delete(route('events.leave', $event))->assertRedirect();

        $this->assertDatabaseCount('event_participations', 0);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Undo'
            && ($job->activity['object']['type'] ?? null) === 'Join'
            && ($job->activity['object']['object'] ?? null) === $event->uri
        );
    }

    public function test_an_unrelated_actor_cannot_accept_an_event_join(): void
    {
        Queue::fake();
        $user = $this->createFullAccount('eventwrongaccept');
        $event = $this->event($this->remoteActor(), ['join_mode' => 'free']);
        $this->actingAs($user)->post(route('events.join', $event));
        $participation = EventParticipation::query()->sole();
        $stranger = $this->createRemoteActor('stranger', 'other.example');

        $status = $this->process([
            'id' => $stranger->uri.'/activities/fake-accept',
            'type' => 'Accept',
            'actor' => $stranger->uri,
            'object' => $participation->activity_uri,
        ], $stranger);

        $this->assertSame(InboxItem::STATUS_IGNORED, $status);
        $this->assertSame(EventParticipation::STATUS_PENDING, $participation->fresh()->status);
    }

    public function test_past_or_external_events_do_not_accept_federated_join(): void
    {
        $user = $this->createFullAccount('eventclosed');
        $past = $this->event($this->remoteActor(), [
            'uri' => 'https://events.example/events/past-interaction',
            'start_at' => now()->subDays(2),
            'end_at' => now()->subDay(),
            'join_mode' => 'free',
        ]);
        $external = $this->event($past->actor, [
            'uri' => 'https://events.example/events/external-interaction',
            'join_mode' => 'external',
        ]);

        $this->actingAs($user)->post(route('events.join', $past))->assertNotFound();
        $this->actingAs($user)->post(route('events.join', $external))->assertNotFound();
        $this->assertDatabaseCount('event_participations', 0);
    }

    private function process(array $activity, Actor $signer): string
    {
        $item = InboxItem::query()->create([
            'is_shared' => false,
            'remote_activity_uri' => $activity['id'],
            'activity_type' => $activity['type'],
            'actor_uri' => $signer->uri,
            'payload' => json_encode($activity, JSON_THROW_ON_ERROR),
            'signature_valid' => true,
            'status' => InboxItem::STATUS_PENDING,
            'received_at' => now(),
        ]);

        return app(InboxActivityProcessor::class)->process($item);
    }

    private function remoteActor(): Actor
    {
        return $this->createRemoteActor('events', 'events.example', [
            'uri' => 'https://events.example/users/events',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function event(Actor $actor, array $attributes = []): Event
    {
        return Event::query()->create([
            'actor_id' => $actor->id,
            'uri' => 'https://events.example/events/'.fake()->uuid(),
            'name' => 'Evento interattivo',
            'visibility' => Event::VISIBILITY_PUBLIC,
            'status' => Event::STATUS_SCHEDULED,
            'start_at' => now()->addDay(),
            'remote_counts_fetched_at' => now(),
            ...$attributes,
        ]);
    }
}
