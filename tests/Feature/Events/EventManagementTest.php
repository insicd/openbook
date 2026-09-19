<?php

namespace Tests\Feature\Events;

use App\Domain\Events\Event;
use App\Domain\Posts\Mention;
use App\Domain\SocialGraph\Follow;
use App\Infrastructure\Media\Media;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class EventManagementTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_owner_can_edit_an_event_and_publish_an_update(): void
    {
        Queue::fake();
        $owner = $this->createFullAccount('eventeditor');
        $follower = $this->followFromRemoteActor($owner->actor->id);
        $event = $this->localEvent($owner->actor->id);

        $this->actingAs($owner)
            ->get(route('events.edit', $event))
            ->assertOk()
            ->assertSee('value="Concerto originale"', false);

        $this->actingAs($owner)->put(route('events.update', $event), $this->validPayload([
            'name' => 'Concerto aggiornato',
            'content' => 'Nuovo programma #noise',
            'visibility' => Event::VISIBILITY_UNLISTED,
        ]))->assertRedirect(route('events.show', $event));

        $event->refresh();
        $this->assertSame('Concerto aggiornato', $event->name);
        $this->assertSame(Event::VISIBILITY_UNLISTED, $event->visibility);
        $this->assertSame(['noise'], $event->hashtags()->pluck('name')->all());
        Queue::assertPushed(DeliverActivityJob::class, function (DeliverActivityJob $job) use ($follower): bool {
            return $job->inboxUrl === $follower->endpoints->shared_inbox
                && ($job->activity['type'] ?? null) === 'Update'
                && ($job->activity['object']['type'] ?? null) === 'Event'
                && ($job->activity['object']['name'] ?? null) === 'Concerto aggiornato';
        });
    }

    public function test_update_reaches_a_remote_actor_even_when_the_mention_is_removed(): void
    {
        Queue::fake();
        $owner = $this->createFullAccount('eventmentioneditor');
        $mentioned = $this->createRemoteActor('oldguest', 'guests.example');
        $event = $this->localEvent($owner->actor->id);
        Mention::query()->create([
            'mentionable_type' => $event->getMorphClass(),
            'mentionable_id' => $event->id,
            'actor_id' => $mentioned->id,
        ]);

        $this->actingAs($owner)->put(route('events.update', $event), $this->validPayload([
            'content' => 'La menzione è stata rimossa.',
        ]))->assertRedirect();

        $this->assertDatabaseMissing('mentions', [
            'mentionable_type' => $event->getMorphClass(),
            'mentionable_id' => $event->id,
            'actor_id' => $mentioned->id,
        ]);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->inboxUrl === $mentioned->endpoints->shared_inbox
            && ($job->activity['type'] ?? null) === 'Update');
    }

    public function test_cancelling_is_idempotent_and_publishes_cancelled_status(): void
    {
        Queue::fake();
        $owner = $this->createFullAccount('eventcanceller');
        $follower = $this->followFromRemoteActor($owner->actor->id);
        $event = $this->localEvent($owner->actor->id);

        $this->actingAs($owner)
            ->patch(route('events.cancel', $event))
            ->assertRedirect(route('events.show', $event));

        $this->assertSame(Event::STATUS_CANCELLED, $event->fresh()->status);
        $this->assertSame('Descrizione originale', $event->fresh()->content);
        Queue::assertPushed(DeliverActivityJob::class, 1);
        Queue::assertPushed(DeliverActivityJob::class, function (DeliverActivityJob $job) use ($follower): bool {
            return $job->inboxUrl === $follower->endpoints->shared_inbox
                && ($job->activity['type'] ?? null) === 'Update'
                && ($job->activity['object']['eventStatus'] ?? null) === 'https://schema.org/EventCancelled';
        });

        $this->actingAs($owner)->patch(route('events.cancel', $event))->assertRedirect();
        Queue::assertPushed(DeliverActivityJob::class, 1);
    }

    public function test_deleting_redacts_event_removes_orphaned_cover_and_exposes_a_tombstone(): void
    {
        Storage::fake('public');
        Queue::fake();
        $owner = $this->createFullAccount('eventdeleter');
        $follower = $this->followFromRemoteActor($owner->actor->id);

        $this->actingAs($owner)->post(route('events.store'), $this->validPayload([
            'cover' => UploadedFile::fake()->image('cover.jpg', 800, 500),
        ]))->assertRedirect();
        $event = Event::query()->with('media')->firstOrFail();
        $media = $event->media->firstOrFail();
        $path = $media->path;
        Storage::disk('public')->assertExists($path);
        Queue::fake();

        $this->actingAs($owner)
            ->delete(route('events.destroy', $event))
            ->assertRedirect(route('events.index'));

        $event->refresh();
        $this->assertTrue($event->isDeleted());
        $this->assertSame('', $event->name);
        $this->assertNull($event->content);
        $this->assertFalse(Media::query()->whereKey($media->id)->exists());
        Storage::disk('public')->assertMissing($path);
        Queue::assertPushed(DeliverActivityJob::class, function (DeliverActivityJob $job) use ($follower): bool {
            return $job->inboxUrl === $follower->endpoints->shared_inbox
                && ($job->activity['type'] ?? null) === 'Delete'
                && ($job->activity['object']['type'] ?? null) === 'Tombstone'
                && ($job->activity['object']['formerType'] ?? null) === 'Event';
        });

        $this->get(route('events.show', $event), ['Accept' => 'application/activity+json'])
            ->assertOk()
            ->assertJsonPath('type', 'Tombstone')
            ->assertJsonPath('formerType', 'Event');
    }

    public function test_delete_is_also_delivered_to_a_remote_actor_mentioned_by_the_event(): void
    {
        Queue::fake();
        $owner = $this->createFullAccount('eventmentiondeleter');
        $mentioned = $this->createRemoteActor('eventguest', 'guests.example');
        $event = $this->localEvent($owner->actor->id);
        Mention::query()->create([
            'mentionable_type' => $event->getMorphClass(),
            'mentionable_id' => $event->id,
            'actor_id' => $mentioned->id,
        ]);

        $this->actingAs($owner)->delete(route('events.destroy', $event))->assertRedirect();

        Queue::assertPushed(DeliverActivityJob::class, 1);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->inboxUrl === $mentioned->endpoints->shared_inbox
            && ($job->activity['type'] ?? null) === 'Delete');
    }

    public function test_other_users_and_remote_events_cannot_be_managed(): void
    {
        $owner = $this->createFullAccount('eventowner');
        $other = $this->createFullAccount('eventstranger');
        $localEvent = $this->localEvent($owner->actor->id);
        $remoteEvent = $this->localEvent($this->createRemoteActor('organizer')->id, [
            'uri' => 'https://remoto.example/events/one',
            'url' => 'https://remoto.example/events/one',
        ]);

        foreach ([$localEvent, $remoteEvent] as $event) {
            $this->actingAs($other)->get(route('events.edit', $event))->assertForbidden();
            $this->actingAs($other)->put(route('events.update', $event), $this->validPayload())->assertForbidden();
            $this->actingAs($other)->patch(route('events.cancel', $event))->assertForbidden();
            $this->actingAs($other)->delete(route('events.destroy', $event))->assertForbidden();
        }
    }

    /** @param array<string, mixed> $overrides */
    private function localEvent(string $actorId, array $overrides = []): Event
    {
        $event = new Event;
        $event->id = $event->newUniqueId();
        $event->forceFill(array_merge([
            'actor_id' => $actorId,
            'uri' => route('events.show', $event->id),
            'url' => route('events.show', $event->id),
            'name' => 'Concerto originale',
            'content' => 'Descrizione originale',
            'visibility' => Event::VISIBILITY_PUBLIC,
            'status' => Event::STATUS_SCHEDULED,
            'join_mode' => 'free',
            'start_at' => now()->addMonth(),
            'timezone' => 'Europe/Rome',
            'published_at' => now(),
        ], $overrides))->save();

        return $event->fresh(['actor']);
    }

    /** @return array<string, mixed> */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Concerto originale',
            'content' => 'Descrizione originale',
            'start_at' => now()->addMonth()->format('Y-m-d\TH:i'),
            'timezone' => 'Europe/Rome',
            'mode' => 'online',
            'participation_url' => 'https://events.example/live',
            'join_mode' => 'free',
            'visibility' => Event::VISIBILITY_PUBLIC,
        ], $overrides);
    }

    private function followFromRemoteActor(string $actorId): mixed
    {
        $follower = $this->createRemoteActor('eventfollower', 'followers.example');
        Follow::query()->create([
            'follower_id' => $follower->id,
            'following_id' => $actorId,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        return $follower;
    }
}
