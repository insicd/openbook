<?php

namespace Tests\Feature\Events;

use App\Domain\Events\Event;
use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class ProfileEventsTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_profile_tabs_follow_the_requested_order(): void
    {
        $user = $this->createFullAccount('tabordinati');

        $this->actingAs($user)
            ->get(route('profile.show', $user->username))
            ->assertOk()
            ->assertSeeInOrder([
                __('openbook.profile.tab_posts'),
                __('openbook.profile.tab_photos'),
                __('openbook.profile.tab_events'),
                __('openbook.profile.tab_activity'),
            ]);
    }

    public function test_remote_actor_events_tab_lists_upcoming_and_archived_events(): void
    {
        Http::fake();
        $viewer = $this->createFullAccount('eventviewer');
        $actor = $this->createRemoteActor('agenda', 'balotta.example');
        $upcoming = $this->event($actor, 'Concerto futuro', now()->addDay());
        $past = $this->event($actor, 'Concerto passato', now()->subDays(2), now()->subDay());

        $this->actingAs($viewer)
            ->get(route('actors.events', $actor))
            ->assertOk()
            ->assertSee($upcoming->name)
            ->assertDontSee($past->name);

        $this->actingAs($viewer)
            ->get(route('actors.events', $actor).'?archivio=1')
            ->assertOk()
            ->assertSee($past->name)
            ->assertDontSee($upcoming->name);
    }

    public function test_local_profile_events_tab_is_public_but_respects_event_visibility(): void
    {
        $user = $this->createFullAccount('eventorganizer');
        $public = $this->event($user->actor, 'Evento pubblico', now()->addDay());
        $private = $this->event(
            $user->actor,
            'Evento privato',
            now()->addDays(2),
            visibility: Event::VISIBILITY_DIRECT,
        );

        $this->get(route('profile.events', $user->username))
            ->assertOk()
            ->assertSee($public->name)
            ->assertDontSee($private->name);

        $this->actingAs($user)
            ->get(route('profile.events', $user->username))
            ->assertOk()
            ->assertSee($public->name)
            ->assertSee($private->name);
    }

    public function test_only_the_profile_owner_sees_the_create_event_action(): void
    {
        $owner = $this->createFullAccount('eventowner');
        $visitor = $this->createFullAccount('eventvisitor');

        $this->actingAs($owner)
            ->get(route('profile.events', $owner->username))
            ->assertOk()
            ->assertSee(route('events.create'));

        $this->actingAs($visitor)
            ->get(route('profile.events', $owner->username))
            ->assertOk()
            ->assertDontSee(route('events.create'));
    }

    public function test_actor_tab_includes_attributed_events_without_leaking_private_ones(): void
    {
        Http::fake();
        $viewer = $this->createFullAccount('eventstranger');
        $creator = $this->createRemoteActor('creator', 'events.example');
        $distributor = $this->createRemoteActor('agenda', 'balotta.example');
        $public = $this->event($creator, 'Evento distribuito', now()->addDay());
        $private = $this->event($creator, 'Evento riservato', now()->addDays(2), visibility: Event::VISIBILITY_DIRECT);
        $public->attributions()->attach($distributor->id, ['position' => 0]);
        $private->attributions()->attach($distributor->id, ['position' => 0]);

        $this->actingAs($viewer)
            ->get(route('actors.events', $distributor))
            ->assertOk()
            ->assertSee($public->name)
            ->assertDontSee($private->name);
    }

    private function event(
        Actor $actor,
        string $name,
        mixed $startAt,
        mixed $endAt = null,
        string $visibility = Event::VISIBILITY_PUBLIC,
    ): Event {
        return Event::query()->create([
            'actor_id' => $actor->id,
            'uri' => 'https://events.example/events/'.fake()->uuid(),
            'name' => $name,
            'visibility' => $visibility,
            'status' => Event::STATUS_SCHEDULED,
            'start_at' => $startAt,
            'end_at' => $endAt,
        ]);
    }
}
