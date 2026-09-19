<?php

namespace App\Http\Controllers;

use App\Application\Services\EventParticipationManager;
use App\Application\Services\ReactionManager;
use App\Domain\Events\Event;
use Illuminate\Http\RedirectResponse;

class EventInteractionController extends Controller
{
    public function __construct(
        private readonly ReactionManager $reactions,
        private readonly EventParticipationManager $participations,
    ) {}

    public function interest(Event $event): RedirectResponse
    {
        $this->assertCanInteract($event);
        $this->reactions->like(auth()->user()->actor, $event);

        return back()->withFragment('event-actions');
    }

    public function removeInterest(Event $event): RedirectResponse
    {
        $this->assertVisible($event);
        $this->reactions->unlike(auth()->user()->actor, $event);

        return back()->withFragment('event-actions');
    }

    public function join(Event $event): RedirectResponse
    {
        $this->assertCanInteract($event);
        abort_unless(in_array($event->join_mode, ['free', 'restricted'], true), 404);
        $this->participations->join(auth()->user()->actor, $event);

        return back()->withFragment('event-actions');
    }

    public function leave(Event $event): RedirectResponse
    {
        $this->assertVisible($event);
        $this->participations->leave(auth()->user()->actor, $event);

        return back()->withFragment('event-actions');
    }

    private function assertCanInteract(Event $event): void
    {
        $this->assertVisible($event);
        abort_unless($event->isOpenForInteractions() && $event->actor !== null, 404);
    }

    private function assertVisible(Event $event): void
    {
        abort_unless(
            Event::query()->whereKey($event->id)->visibleTo(auth()->user()->actor)->exists(),
            404,
        );
    }
}
