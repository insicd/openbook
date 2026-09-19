<?php

namespace Tests\Feature\Events;

use App\Domain\Events\Event;
use App\Domain\Events\EventAnnounce;
use App\Domain\Events\EventAttachment;
use App\Domain\Events\EventLink;
use App\Domain\Events\EventParticipation;
use App\Domain\Posts\Hashtag;
use App\Infrastructure\Media\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class EventDomainTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_event_relations_and_casts_use_the_dedicated_schema(): void
    {
        $creator = $this->createFullAccount('eventcreator')->actor;
        $recipient = $this->createFullAccount('eventrecipient')->actor;
        $event = Event::query()->create([
            'actor_id' => $creator->id,
            'uri' => 'https://events.example/events/concert',
            'name' => 'Concerto di prova',
            'custom_emojis' => ['music' => 'https://events.example/emoji/music.png'],
            'visibility' => Event::VISIBILITY_DIRECT,
            'status' => Event::STATUS_SCHEDULED,
            'sensitive' => false,
            'is_online' => true,
            'start_at' => '2026-10-10 18:00:00',
            'end_at' => '2026-10-10 21:00:00',
            'timezone' => 'Europe/Rome',
            'utc_offset_minutes' => 120,
        ]);

        $event->attributions()->attach($creator->id, ['position' => 0]);
        $event->recipients()->attach($recipient->id);
        $hashtag = Hashtag::query()->create(['name' => 'concerto']);
        $event->hashtags()->attach($hashtag->id);
        $event->location()->create([
            'name' => 'Bologna',
            'locality' => 'Bologna',
            'country_code' => 'IT',
            'latitude' => 44.4949,
            'longitude' => 11.3426,
            'source' => 'remote',
        ]);

        $event = $event->fresh(['actor', 'attributions', 'recipients', 'hashtags', 'location']);

        $this->assertTrue($event->is_online);
        $this->assertFalse($event->sensitive);
        $this->assertSame(['music' => 'https://events.example/emoji/music.png'], $event->custom_emojis);
        $this->assertSame('2026-10-10 18:00:00', $event->start_at->format('Y-m-d H:i:s'));
        $this->assertTrue($event->actor->is($creator));
        $this->assertTrue($event->attributions->first()->is($creator));
        $this->assertTrue($event->recipients->first()->is($recipient));
        $this->assertTrue($event->hashtags->first()->is($hashtag));
        $this->assertSame('Bologna', $event->location->locality);
    }

    public function test_deleting_an_event_cascades_domain_relations_but_not_shared_media(): void
    {
        $actor = $this->createFullAccount('eventcascade')->actor;
        $event = $this->createEvent($actor->id);
        $hashtag = Hashtag::query()->create(['name' => 'festival']);
        $media = Media::query()->create([
            'actor_id' => $actor->id,
            'disk' => 'public',
            'path' => 'remote/event-cover.jpg',
            'remote_url' => 'https://events.example/cover.jpg',
            'mime_type' => 'image/jpeg',
            'byte_size' => 0,
        ]);

        $event->attributions()->attach($actor->id, ['position' => 0]);
        $event->recipients()->attach($actor->id);
        $event->hashtags()->attach($hashtag->id);
        $event->location()->create(['name' => 'Roma', 'source' => 'remote']);
        EventAttachment::query()->create([
            'event_id' => $event->id,
            'media_id' => $media->id,
            'position' => 0,
        ]);
        EventLink::query()->create([
            'event_id' => $event->id,
            'position' => 0,
            'url' => 'https://events.example/tickets',
        ]);
        EventAnnounce::query()->create([
            'event_id' => $event->id,
            'actor_id' => $actor->id,
            'uri' => 'https://events.example/activities/announce-1',
        ]);
        EventParticipation::query()->create([
            'event_id' => $event->id,
            'actor_id' => $actor->id,
            'status' => EventParticipation::STATUS_ACCEPTED,
            'activity_uri' => 'https://events.example/activities/join-1',
        ]);

        $event->delete();

        foreach (['event_locations', 'event_attributions', 'event_recipients', 'event_hashtags', 'event_attachments', 'event_links', 'event_announces', 'event_participations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }

        $this->assertDatabaseHas('media', ['id' => $media->id]);
        $this->assertDatabaseHas('hashtags', ['id' => $hashtag->id]);
    }

    public function test_hard_actor_deletion_removes_roles_without_deleting_the_event_row(): void
    {
        $creator = $this->createFullAccount('deletedcreator')->actor;
        $other = $this->createFullAccount('remainingorganizer')->actor;
        $event = $this->createEvent($creator->id);

        $event->attributions()->attach($creator->id, ['position' => 0]);
        $event->attributions()->attach($other->id, ['position' => 1]);
        $event->recipients()->attach($creator->id);

        $creator->delete();

        $event->refresh();
        $this->assertNull($event->actor_id);
        $this->assertDatabaseMissing('event_attributions', [
            'event_id' => $event->id,
            'actor_id' => $creator->id,
        ]);
        $this->assertDatabaseHas('event_attributions', [
            'event_id' => $event->id,
            'actor_id' => $other->id,
        ]);
        $this->assertDatabaseMissing('event_recipients', [
            'event_id' => $event->id,
            'actor_id' => $creator->id,
        ]);
    }

    public function test_event_configuration_has_safe_defaults(): void
    {
        $this->assertSame(12, config('openbook.events.default_duration_hours'));
        $this->assertSame(4, config('openbook.events.cache_ttl_hours'));
    }

    private function createEvent(?string $actorId): Event
    {
        return Event::query()->create([
            'actor_id' => $actorId,
            'uri' => 'https://events.example/events/'.fake()->uuid(),
            'name' => 'Evento di prova',
            'visibility' => Event::VISIBILITY_PUBLIC,
            'status' => Event::STATUS_SCHEDULED,
            'start_at' => now()->addDay(),
        ]);
    }
}
