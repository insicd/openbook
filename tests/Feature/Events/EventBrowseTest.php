<?php

namespace Tests\Feature\Events;

use App\Domain\Events\Event;
use App\Domain\Posts\Hashtag;
use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class EventBrowseTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

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

    public function test_unlisted_event_has_a_public_permalink_but_is_not_discoverable(): void
    {
        $event = $this->event($this->remoteActor(), [
            'name' => 'Evento non elencato',
            'visibility' => Event::VISIBILITY_UNLISTED,
        ]);

        $this->get(route('events.index'))->assertDontSee($event->name);
        $this->get(route('events.show', $event))->assertOk()->assertSee($event->name);
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
