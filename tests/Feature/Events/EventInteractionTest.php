<?php

namespace Tests\Feature\Events;

use App\Application\Services\EventParticipationManager;
use App\Domain\Events\Event;
use App\Domain\Events\EventComment;
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

    public function test_remote_join_to_a_free_local_event_is_accepted_and_counted(): void
    {
        Queue::fake();
        $owner = $this->createFullAccount('localfreeowner');
        $guest = $this->createRemoteActor('remoteguest', 'guest.example');
        $event = $this->localEvent($owner->actor, ['join_mode' => 'free']);
        $joinUri = $guest->uri.'/activities/join-free';

        $status = $this->process([
            'id' => $joinUri,
            'type' => 'Join',
            'actor' => $guest->uri,
            'object' => $event->uri,
        ], $guest);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $participation = EventParticipation::query()->sole();
        $this->assertSame(EventParticipation::STATUS_ACCEPTED, $participation->status);
        $this->assertSame(1, $event->fresh()->participant_count);
        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $owner->id,
            'actor_id' => $guest->id,
            'type' => Notification::TYPE_EVENT_JOINED,
            'notifiable_type' => 'event_participation',
            'notifiable_id' => $participation->id,
        ]);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Accept'
            && ($job->activity['object']['id'] ?? null) === $joinUri
            && $job->activity['actor'] === $owner->actor->activityPubId()
        );

        app(EventParticipationManager::class)->receiveJoin($guest, $event, $joinUri);
        $this->assertDatabaseCount('event_participations', 1);
        Queue::assertPushed(DeliverActivityJob::class, 1);
    }

    public function test_restricted_local_event_notifies_owner_and_can_be_accepted(): void
    {
        Queue::fake();
        $owner = $this->createFullAccount('restrictedowner');
        $guest = $this->createRemoteActor('restrictedguest', 'guest.example');
        $event = $this->localEvent($owner->actor, ['join_mode' => 'restricted']);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process([
            'id' => $guest->uri.'/activities/join-restricted',
            'type' => 'Join',
            'actor' => $guest->uri,
            'object' => $event->uri,
        ], $guest));

        $participation = EventParticipation::query()->sole();
        $this->assertSame(EventParticipation::STATUS_PENDING, $participation->status);
        $this->assertSame(0, $event->fresh()->participant_count);
        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $owner->id,
            'actor_id' => $guest->id,
            'type' => Notification::TYPE_EVENT_JOIN_REQUEST,
            'notifiable_type' => 'event_participation',
            'notifiable_id' => $participation->id,
        ]);
        $this->actingAs($owner)->get(route('events.show', $event))
            ->assertOk()
            ->assertSeeText(__('openbook.events.pending_participations'))
            ->assertSee($guest->handle());

        $this->actingAs($owner)
            ->post(route('events.participations.accept', [$event, $participation]))
            ->assertRedirect();

        $this->assertSame(EventParticipation::STATUS_ACCEPTED, $participation->fresh()->status);
        $this->assertSame(1, $event->fresh()->participant_count);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Accept'
            && ($job->activity['object']['id'] ?? null) === $participation->activity_uri
        );
    }

    public function test_restricted_local_event_request_can_be_rejected(): void
    {
        Queue::fake();
        $owner = $this->createFullAccount('rejectingowner');
        $guest = $this->createRemoteActor('rejectedguest', 'guest.example');
        $event = $this->localEvent($owner->actor, ['join_mode' => 'restricted']);
        $this->process([
            'id' => $guest->uri.'/activities/join-reject',
            'type' => 'Join',
            'actor' => $guest->uri,
            'object' => $event->uri,
        ], $guest);
        $participation = EventParticipation::query()->sole();

        $this->actingAs($owner)
            ->post(route('events.participations.reject', [$event, $participation]))
            ->assertRedirect();

        $this->assertSame(EventParticipation::STATUS_REJECTED, $participation->fresh()->status);
        $this->assertSame(0, $event->fresh()->participant_count);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Reject');
    }

    public function test_remote_leave_and_undo_join_remove_local_participation(): void
    {
        Queue::fake();
        $owner = $this->createFullAccount('leaveowner');
        $guest = $this->createRemoteActor('leavingguest', 'guest.example');
        $event = $this->localEvent($owner->actor, ['join_mode' => 'free']);
        $joinUri = $guest->uri.'/activities/join-leave';
        $join = [
            'id' => $joinUri,
            'type' => 'Join',
            'actor' => $guest->uri,
            'object' => $event->uri,
        ];
        $this->process($join, $guest);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process([
            'id' => $guest->uri.'/activities/leave',
            'type' => 'Leave',
            'actor' => $guest->uri,
            'object' => $event->uri,
        ], $guest));
        $this->assertDatabaseCount('event_participations', 0);
        $this->assertSame(0, $event->fresh()->participant_count);

        $secondJoin = [...$join, 'id' => $guest->uri.'/activities/join-again'];
        $this->process($secondJoin, $guest);
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process([
            'id' => $guest->uri.'/activities/undo-join',
            'type' => 'Undo',
            'actor' => $guest->uri,
            'object' => $secondJoin,
        ], $guest));
        $this->assertDatabaseCount('event_participations', 0);
        $this->assertSame(0, $event->fresh()->participant_count);
    }

    public function test_local_user_can_join_a_local_event_without_a_delivery_job(): void
    {
        Queue::fake();
        $owner = $this->createFullAccount('sameinstanceowner');
        $guest = $this->createFullAccount('sameinstanceguest');
        $event = $this->localEvent($owner->actor, ['join_mode' => 'free']);

        $this->actingAs($guest)->post(route('events.join', $event))->assertRedirect();

        $this->assertDatabaseHas('event_participations', [
            'event_id' => $event->id,
            'actor_id' => $guest->actor->id,
            'status' => EventParticipation::STATUS_ACCEPTED,
        ]);
        $this->assertSame(1, $event->fresh()->participant_count);
        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $owner->id,
            'actor_id' => $guest->actor->id,
            'type' => Notification::TYPE_EVENT_JOINED,
        ]);
        Queue::assertNotPushed(DeliverActivityJob::class);
    }

    public function test_local_restricted_request_is_decided_without_federation_and_notifies_guest(): void
    {
        Queue::fake();
        $owner = $this->createFullAccount('localrequestowner');
        $guest = $this->createFullAccount('localrequestguest');
        $event = $this->localEvent($owner->actor, ['join_mode' => 'restricted']);

        $this->actingAs($guest)->post(route('events.join', $event))->assertRedirect();
        $participation = EventParticipation::query()->sole();
        $this->assertSame(EventParticipation::STATUS_PENDING, $participation->status);

        $this->actingAs($owner)
            ->post(route('events.participations.accept', [$event, $participation]))
            ->assertRedirect();

        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $guest->id,
            'actor_id' => $owner->actor->id,
            'type' => Notification::TYPE_EVENT_JOIN_ACCEPTED,
            'notifiable_id' => $participation->id,
        ]);
        Queue::assertNotPushed(DeliverActivityJob::class);
    }

    public function test_only_event_owner_can_decide_a_participation_request(): void
    {
        $owner = $this->createFullAccount('decisionowner');
        $stranger = $this->createFullAccount('decisionstranger');
        $guest = $this->createRemoteActor('decisionguest', 'guest.example');
        $event = $this->localEvent($owner->actor, ['join_mode' => 'restricted']);
        $this->process([
            'id' => $guest->uri.'/activities/join-decision',
            'type' => 'Join',
            'actor' => $guest->uri,
            'object' => $event->uri,
        ], $guest);
        $participation = EventParticipation::query()->sole();

        $this->actingAs($stranger)
            ->post(route('events.participations.accept', [$event, $participation]))
            ->assertForbidden();
        $this->assertSame(EventParticipation::STATUS_PENDING, $participation->fresh()->status);
    }

    public function test_join_claiming_another_actor_is_ignored(): void
    {
        $owner = $this->createFullAccount('claimedowner');
        $signer = $this->createRemoteActor('joinsigner', 'guest.example');
        $claimed = $this->createRemoteActor('claimedguest', 'other.example');
        $event = $this->localEvent($owner->actor, ['join_mode' => 'free']);

        $status = $this->process([
            'id' => $signer->uri.'/activities/forged-join',
            'type' => 'Join',
            'actor' => $claimed->uri,
            'object' => $event->uri,
        ], $signer);

        $this->assertSame(InboxItem::STATUS_IGNORED, $status);
        $this->assertDatabaseCount('event_participations', 0);
    }

    public function test_remote_like_and_undo_are_applied_to_a_local_event_comment(): void
    {
        $owner = $this->createFullAccount('eventcommentowner');
        $remote = $this->createRemoteActor('eventcommentliker', 'guest.example');
        $event = $this->localEvent($owner->actor);
        $comment = EventComment::query()->create([
            'event_id' => $event->id,
            'actor_id' => $owner->actor->id,
            'uri' => route('event-comments.show', fake()->uuid()),
            'body' => 'Commento locale.',
            'status' => EventComment::STATUS_PUBLISHED,
        ]);
        $like = [
            'id' => $remote->uri.'/activities/like-event-comment',
            'type' => 'Like',
            'actor' => $remote->uri,
            'object' => $comment->uri,
        ];

        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($like, $remote));
        $this->assertSame(1, $comment->fresh()->likes_count);
        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $owner->id,
            'actor_id' => $remote->id,
            'type' => Notification::TYPE_LIKE,
            'notifiable_type' => 'event_comment',
            'notifiable_id' => $comment->id,
        ]);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process([
            'id' => $remote->uri.'/activities/undo-like-event-comment',
            'type' => 'Undo',
            'actor' => $remote->uri,
            'object' => $like,
        ], $remote));
        $this->assertSame(0, $comment->fresh()->likes_count);
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

    /** @param array<string, mixed> $attributes */
    private function localEvent(Actor $actor, array $attributes = []): Event
    {
        $event = new Event;
        $event->id = $event->newUniqueId();
        $event->forceFill([
            'actor_id' => $actor->id,
            'uri' => route('events.show', $event->id),
            'url' => route('events.show', $event->id),
            'name' => 'Evento locale interattivo',
            'visibility' => Event::VISIBILITY_PUBLIC,
            'status' => Event::STATUS_SCHEDULED,
            'start_at' => now()->addDay(),
            'participant_count' => 0,
            'published_at' => now(),
            ...$attributes,
        ])->save();

        return $event->fresh('actor');
    }
}
