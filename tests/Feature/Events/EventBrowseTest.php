<?php

namespace Tests\Feature\Events;

use App\Domain\Events\Event;
use App\Domain\Events\EventAttachment;
use App\Domain\Locations\GeoCity;
use App\Domain\Posts\Hashtag;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Infrastructure\Media\Media;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class EventBrowseTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_event_composer_prototype_is_reserved_to_authenticated_users(): void
    {
        $this->get(route('events.create'))->assertRedirect(route('login'));

        $user = $this->createFullAccount('eventcomposer');

        $this->actingAs($user)
            ->get(route('events.index'))
            ->assertOk()
            ->assertSee(route('events.create'));

        $this->actingAs($user)
            ->get(route('events.create'))
            ->assertOk()
            ->assertSeeText(__('openbook.events.composer.title'))
            ->assertSee('name="visibility" value="public"', false)
            ->assertSee('id="event-listed" checked', false)
            ->assertSee('value="Europe/Rome"', false)
            ->assertDontSee('name="category"', false)
            ->assertSee('action="'.route('events.store').'"', false);
    }

    public function test_local_user_can_create_an_unlisted_hybrid_event(): void
    {
        Storage::fake('public');
        config()->set('openbook.locations.catalog_ready', true);
        $user = $this->createFullAccount('eventauthor');
        $mentioned = $this->createFullAccount('eventfriend');
        $city = GeoCity::query()->create([
            'geoname_id' => 658225,
            'name' => 'Helsinki',
            'ascii_name' => 'Helsinki',
            'latitude' => 60.1695,
            'longitude' => 24.9354,
            'latitude_bucket' => 60,
            'longitude_bucket' => 24,
            'country_code' => 'FI',
            'country_name' => 'Finland',
            'admin1_code' => '01',
            'admin1_name' => 'Uusimaa',
            'feature_code' => 'PPLC',
            'population' => 658864,
            'catalog_batch' => '847ef4c2-1486-4a4f-a234-345436782f5f',
        ]);

        $response = $this->actingAs($user)->post(route('events.store'), [
            'name' => 'Serata punk',
            'content' => 'Suoniamo con @eventfriend #crust',
            'cover' => UploadedFile::fake()->image('locandina.jpg', 1200, 800),
            'cover_alt' => 'Locandina della serata',
            'sensitive' => '1',
            'start_at' => '2027-01-10T18:00',
            'end_at' => '2027-01-10T21:00',
            'timezone' => 'Europe/Helsinki',
            'mode' => 'hybrid',
            'participation_url' => 'https://events.example/join',
            'location_id' => $city->geoname_id,
            'location_label' => $city->label(),
            'venue' => 'Lepakkomies',
            'address' => 'Helsinginkatu 1',
            'join_mode' => 'restricted',
            'visibility' => Event::VISIBILITY_UNLISTED,
        ]);

        $event = Event::query()->with(['location', 'hashtags', 'mentions', 'media.thumbnail'])->firstOrFail();
        $response->assertRedirect(route('events.show', $event));
        $this->assertSame($user->actor->id, $event->actor_id);
        $this->assertSame(route('events.show', $event), $event->uri);
        $this->assertSame('2027-01-10 16:00:00', $event->start_at->copy()->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('Europe/Helsinki', $event->timezone);
        $this->assertSame(120, $event->utc_offset_minutes);
        $this->assertTrue($event->sensitive);
        $this->assertTrue($event->is_online);
        $this->assertSame('Lepakkomies', $event->location?->name);
        $this->assertSame('Helsinki', $event->location?->locality);
        $this->assertSame(['crust'], $event->hashtags->pluck('name')->all());
        $this->assertSame([$mentioned->actor->id], $event->mentions->pluck('actor_id')->all());
        $this->assertCount(1, $event->media);
        Storage::disk('public')->assertExists($event->media->first()->path);
        $this->actingAs($user)
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertDontSeeText(__('openbook.events.source'));

        $this->get(route('events.show', $event), ['Accept' => 'application/activity+json'])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/activity+json; charset=utf-8')
            ->assertJsonPath('type', 'Event')
            ->assertJsonPath('id', route('events.show', $event))
            ->assertJsonPath('attributedTo', $user->actor->activityPubId())
            ->assertJsonPath('startTime', '2027-01-10T18:00:00+02:00')
            ->assertJsonPath('endTime', '2027-01-10T21:00:00+02:00')
            ->assertJsonPath('timezone', 'Europe/Helsinki')
            ->assertJsonPath('location.name', 'Lepakkomies')
            ->assertJsonPath('location.address.addressLocality', 'Helsinki')
            ->assertJsonPath('attachment.0.name', 'Locandina della serata')
            ->assertJsonPath('tag.0.name', '#crust');
    }

    public function test_event_creation_validates_conditional_location_and_participation_fields(): void
    {
        $user = $this->createFullAccount('eventvalidation');

        $this->actingAs($user)->from(route('events.create'))->post(route('events.store'), [
            'name' => 'Evento incompleto',
            'content' => 'Descrizione',
            'start_at' => '2027-01-10T18:00',
            'timezone' => 'Europe/Rome',
            'mode' => 'hybrid',
            'join_mode' => 'external',
            'visibility' => Event::VISIBILITY_PUBLIC,
        ])->assertRedirect(route('events.create'))
            ->assertSessionHasErrors(['location_id', 'participation_url']);

        $this->assertDatabaseCount('events', 0);
    }

    public function test_event_creation_queues_a_create_activity_for_remote_followers(): void
    {
        Queue::fake();
        $user = $this->createFullAccount('eventpublisher');
        $follower = $this->createRemoteActor('eventreader', 'reader.example');
        Follow::query()->create([
            'follower_id' => $follower->id,
            'following_id' => $user->actor->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        $this->actingAs($user)->post(route('events.store'), [
            'name' => 'Evento federato',
            'content' => 'Descrizione pubblica #live',
            'start_at' => '2027-03-20T20:00',
            'timezone' => 'Europe/Rome',
            'mode' => 'online',
            'participation_url' => 'https://events.example/live',
            'join_mode' => 'free',
            'visibility' => Event::VISIBILITY_PUBLIC,
        ])->assertRedirect();

        Queue::assertPushed(DeliverActivityJob::class, function (DeliverActivityJob $job) use ($follower): bool {
            return $job->inboxUrl === $follower->endpoints->shared_inbox
                && ($job->activity['type'] ?? null) === 'Create'
                && ($job->activity['object']['type'] ?? null) === 'Event'
                && ($job->activity['object']['name'] ?? null) === 'Evento federato';
        });
    }

    public function test_public_upcoming_events_are_listed_and_past_events_are_archived(): void
    {
        $actor = $this->remoteActor();
        $upcoming = $this->event($actor, ['name' => 'Concerto futuro', 'start_at' => now()->addDay()]);
        $past = $this->event($actor, [
            'uri' => 'https://events.example/events/past',
            'name' => 'Concerto passato',
            'start_at' => now()->subDays(2),
            'end_at' => now()->subDay(),
        ]);

        $this->get(route('events.index'))
            ->assertOk()
            ->assertSee($upcoming->name)
            ->assertDontSee($past->name);

        $this->get(route('events.archive'))
            ->assertOk()
            ->assertSee($past->name)
            ->assertDontSee($upcoming->name);
    }

    public function test_event_card_shows_the_structured_location(): void
    {
        $event = $this->event($this->remoteActor(), ['name' => 'Concerto cittadino']);
        $event->location()->create([
            'locality' => 'Milano',
            'name' => 'Teatro Verdi',
            'region' => 'Lombardia',
            'country_name' => 'Italia',
            'source' => 'remote',
        ]);

        $this->get(route('events.index'))
            ->assertOk()
            ->assertSeeTextInOrder(['Teatro Verdi', 'Milano', 'Lombardia (Italia)']);
    }

    public function test_event_card_uses_the_country_code_when_the_name_is_missing(): void
    {
        $event = $this->event($this->remoteActor(), ['name' => 'Concerto internazionale']);
        $event->location()->create([
            'name' => 'Sala concerti',
            'locality' => 'Helsinki',
            'region' => 'Uusimaa',
            'country_code' => 'FI',
            'source' => 'remote',
        ]);

        $this->get(route('events.index'))
            ->assertOk()
            ->assertSeeTextInOrder(['Sala concerti', 'Helsinki', 'Uusimaa (FI)']);
    }

    public function test_unlisted_event_has_a_public_permalink_but_is_not_discoverable(): void
    {
        $event = $this->event($this->remoteActor(), [
            'name' => 'Evento non elencato',
            'visibility' => Event::VISIBILITY_UNLISTED,
        ]);

        $this->get(route('events.index'))->assertDontSee($event->name);
        $this->get(route('events.show', $event))->assertOk()->assertSee($event->name);
    }

    public function test_public_event_exposes_open_graph_metadata(): void
    {
        $event = $this->event($this->remoteActor(), [
            'name' => 'Festival & musica',
            'summary' => "Una serata speciale.\nSeconda riga.",
            'content' => 'Concerti dal vivo.',
            'remote_counts_fetched_at' => now(),
        ]);
        $media = Media::query()->create([
            'actor_id' => $event->actor_id,
            'disk' => 'remote',
            'path' => 'remote/event-cover',
            'remote_url' => 'https://cdn.example/event-cover.jpg',
            'mime_type' => 'image/jpeg',
            'byte_size' => 0,
            'width' => 1200,
            'height' => 630,
            'alt_text' => 'Locandina del festival',
        ]);
        EventAttachment::query()->create([
            'event_id' => $event->id,
            'media_id' => $media->id,
            'position' => 0,
        ]);

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('<meta property="og:type" content="website">', false)
            ->assertSee('<meta property="og:url" content="'.route('events.show', $event).'">', false)
            ->assertSee('<meta property="og:title" content="Festival &amp; musica">', false)
            ->assertSee('<meta property="og:description" content="Una serata speciale. Seconda riga. Concerti dal vivo.">', false)
            ->assertSee('<meta property="og:image" content="https://cdn.example/event-cover.jpg">', false)
            ->assertSee('<meta property="og:image:width" content="1200">', false)
            ->assertSee('<meta property="og:image:height" content="630">', false)
            ->assertSee('<meta property="og:image:alt" content="Locandina del festival">', false)
            ->assertDontSee('twitter:card', false);
    }

    public function test_non_public_event_does_not_expose_open_graph_metadata(): void
    {
        $event = $this->event($this->remoteActor(), [
            'visibility' => Event::VISIBILITY_UNLISTED,
            'remote_counts_fetched_at' => now(),
        ]);

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertDontSee('property="og:', false);
    }

    public function test_event_list_uses_infinite_scroll_when_there_are_more_pages(): void
    {
        $actor = $this->remoteActor();

        foreach (range(1, 19) as $position) {
            $this->event($actor, [
                'name' => 'Evento '.$position,
                'start_at' => now()->addDays($position),
            ]);
        }

        $response = $this->get(route('events.index'));

        $response->assertOk();
        $response->assertSee('data-infinite-scroll', false);
        $response->assertSee('data-next-url=', false);
        $response->assertSee('ob-pagination', false);
        $response->assertSee(__('openbook.events.infinite_scroll.end'));
        $response->assertSee(__('openbook.infinite_scroll.next'));
    }

    public function test_upcoming_event_scroll_returns_only_the_grid_and_keeps_your_events_on_the_initial_page(): void
    {
        $actor = $this->remoteActor();
        $viewer = $this->createFullAccount('eventscrollviewer');
        $invitation = $this->event($actor, [
            'name' => 'Invito riservato',
            'visibility' => Event::VISIBILITY_DIRECT,
        ]);
        $invitation->recipients()->attach($viewer->actor->id);

        foreach (range(1, 37) as $position) {
            $this->event($actor, [
                'name' => 'Evento pubblico '.$position,
                'start_at' => now()->addDays($position),
            ]);
        }

        $firstPage = $this->actingAs($viewer)->get(route('events.index'));
        $firstPage->assertOk();
        $firstPage->assertSee(__('openbook.events.your_events'));
        $firstPage->assertSee('Invito riservato');
        $firstPage->assertSee('data-next-url=', false);

        $fragment = $this->actingAs($viewer)->get(route('events.index', ['page' => 2]), [
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
        $fragment->assertOk();
        $fragment->assertSee('data-infinite-scroll', false);
        $fragment->assertSee('Evento pubblico 19');
        $fragment->assertSee('data-next-url=', false);
        $fragment->assertSee('page=3', false);
        $fragment->assertDontSee('Invito riservato');
        $fragment->assertDontSee(__('openbook.events.your_events'));
        $fragment->assertDontSee('<!DOCTYPE html>', false);
        $fragment->assertDontSee('ob-pagination', false);

        $fullPage = $this->actingAs($viewer)->get(route('events.index', ['page' => 2]));
        $fullPage->assertOk();
        $fullPage->assertSee('<!DOCTYPE html>', false);
        $fullPage->assertSee('Invito riservato');
        $fullPage->assertSee('Evento pubblico 19');
    }

    public function test_archive_scroll_returns_only_the_grid(): void
    {
        $actor = $this->remoteActor();

        foreach (range(1, 19) as $position) {
            $this->event($actor, [
                'name' => 'Evento passato '.$position,
                'start_at' => now()->subDays($position + 1),
                'end_at' => now()->subDays($position),
            ]);
        }

        $fragment = $this->get(route('events.archive', ['page' => 2]), [
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
        $fragment->assertOk();
        $fragment->assertSee('data-infinite-scroll', false);
        $fragment->assertSee('Evento passato 19');
        $fragment->assertDontSee('Evento passato 18');
        $fragment->assertDontSee('<!DOCTYPE html>', false);
        $fragment->assertDontSee('data-next-url=', false);
    }

    public function test_upcoming_event_is_shown_on_its_hashtag_page(): void
    {
        $hashtag = Hashtag::query()->create(['name' => 'crust']);
        $event = $this->event($this->remoteActor(), ['name' => 'Crust Fest']);
        $event->hashtags()->attach($hashtag->id);

        $this->get(route('hashtags.show', $hashtag->name))
            ->assertOk()
            ->assertSee('Crust Fest')
            ->assertSee(route('events.show', $event), false);
    }

    public function test_detail_removes_the_repeated_content_from_the_summary(): void
    {
        $content = "Descrizione completa dell'evento.\nSeconda riga.";
        $event = $this->event($this->remoteActor(), [
            'summary' => "Intestazione evento\n\n{$content}",
            'content' => $content,
            'remote_counts_fetched_at' => now(),
        ]);

        $response = $this->get(route('events.show', $event))->assertOk();

        $response->assertSeeText('Intestazione evento');
        $renderedText = html_entity_decode(strip_tags($response->getContent()));
        $this->assertSame(1, substr_count($renderedText, "Descrizione completa dell'evento."));
    }

    public function test_remote_event_detail_links_to_its_original_page(): void
    {
        $event = $this->event($this->remoteActor(), [
            'url' => 'https://events.example/events/original-page',
        ]);

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertSeeText(__('openbook.events.source'))
            ->assertSee($event->url, false);
    }

    public function test_sensitive_event_hides_media_and_description_until_revealed(): void
    {
        $event = $this->event($this->remoteActor(), [
            'name' => 'Evento sensibile',
            'summary' => 'Anteprima riservata',
            'content' => 'Descrizione riservata',
            'sensitive' => true,
            'remote_counts_fetched_at' => now(),
        ]);

        $response = $this->get(route('events.show', $event))->assertOk();

        $response->assertSee('<details class="ob-post__cw ob-event-detail__sensitive">', false);
        $response->assertSee(__('openbook.events.sensitive_content'));
        $response->assertSee('Anteprima riservata');
        $response->assertSee('Descrizione riservata');
    }

    public function test_direct_event_is_visible_only_to_a_recorded_recipient(): void
    {
        $event = $this->event($this->remoteActor(), [
            'visibility' => Event::VISIBILITY_DIRECT,
            'name' => 'Invito riservato',
        ]);
        $recipient = $this->createFullAccount('eventguest');
        $stranger = $this->createFullAccount('eventstranger');
        $event->recipients()->attach($recipient->actor->id);

        $this->get(route('events.show', $event))->assertNotFound();
        $this->actingAs($stranger)->get(route('events.show', $event))->assertNotFound();
        $this->actingAs($recipient)->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('Invito riservato');
        $this->actingAs($recipient)->get(route('events.index'))
            ->assertOk()
            ->assertSee(__('openbook.events.your_events'))
            ->assertSee('Invito riservato');
    }

    public function test_followers_event_is_discoverable_only_by_an_authorized_viewer(): void
    {
        $event = $this->event($this->remoteActor(), [
            'visibility' => Event::VISIBILITY_FOLLOWERS,
            'name' => 'Evento per follower',
        ]);
        $account = $this->createFullAccount('eventfollower');
        $event->recipients()->attach($account->actor->id);

        $this->get(route('events.index'))->assertDontSee($event->name);
        $this->actingAs($account)->get(route('events.index'))
            ->assertOk()
            ->assertSee($event->name);
    }

    public function test_authorized_viewer_sees_a_deleted_event_as_a_tombstone(): void
    {
        $account = $this->createFullAccount('eventdeleted');
        $event = $this->event($this->remoteActor(), [
            'name' => '',
            'visibility' => Event::VISIBILITY_DIRECT,
            'status' => Event::STATUS_DELETED,
            'deleted_at' => now(),
        ]);
        $event->recipients()->attach($account->actor->id);

        $this->actingAs($account)
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertSee(__('openbook.events.deleted_title'));
    }

    public function test_event_detail_refreshes_a_stale_remote_object_only_once_per_ttl(): void
    {
        $actor = $this->remoteActor();
        $event = $this->event($actor, ['name' => 'Titolo vecchio']);
        $document = [
            'id' => $event->uri,
            'type' => 'Event',
            'actor' => $actor->uri,
            'attributedTo' => $actor->uri,
            'name' => 'Titolo aggiornato',
            'content' => 'Descrizione aggiornata',
            'startTime' => now()->addDay()->toAtomString(),
            'updated' => now()->toAtomString(),
            'participantCount' => 42,
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        ];
        Http::fake([$event->uri => Http::response($document, 200, ['Content-Type' => 'application/activity+json'])]);

        $this->get(route('events.show', $event))->assertOk()->assertSee('Titolo aggiornato');
        $this->get(route('events.show', $event))->assertOk()->assertSee('Titolo aggiornato');

        Http::assertSentCount(1);
        $this->assertSame(42, $event->fresh()->participant_count);
        $this->assertNotNull($event->fresh()->remote_counts_fetched_at);
    }

    public function test_refresh_can_restrict_a_previously_public_event_without_leaking_it(): void
    {
        $actor = $this->remoteActor();
        $event = $this->event($actor, ['name' => 'Evento inizialmente pubblico']);
        Http::fake([$event->uri => Http::response([
            'id' => $event->uri,
            'type' => 'Event',
            'actor' => $actor->uri,
            'attributedTo' => $actor->uri,
            'name' => 'Evento ora privato',
            'startTime' => now()->addDay()->toAtomString(),
            'updated' => now()->toAtomString(),
            'to' => ['https://events.example/users/invitato'],
        ], 200, ['Content-Type' => 'application/activity+json'])]);

        $this->get(route('events.show', $event))->assertNotFound();
        $this->assertSame(Event::VISIBILITY_DIRECT, $event->fresh()->visibility);
    }

    private function remoteActor(): Actor
    {
        return $this->createRemoteActor('agenda', 'events.example', [
            'uri' => 'https://events.example/users/agenda',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function event(Actor $actor, array $attributes = []): Event
    {
        return Event::query()->create([
            'actor_id' => $actor->id,
            'uri' => 'https://events.example/events/'.fake()->uuid(),
            'name' => 'Evento di prova',
            'visibility' => Event::VISIBILITY_PUBLIC,
            'status' => Event::STATUS_SCHEDULED,
            'start_at' => now()->addDay(),
            ...$attributes,
        ]);
    }
}
