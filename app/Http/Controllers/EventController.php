<?php

namespace App\Http\Controllers;

use App\Application\Services\QuotedEventResolver;
use App\Domain\Events\Event;
use App\Domain\Events\EventComment;
use App\Domain\Events\EventCommentThread;
use App\Domain\Events\EventParticipation;
use App\Federation\Events\RemoteEventRefresher;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    private const CARD_RELATIONS = ['actor.user.profile', 'location', 'media.thumbnail', 'attributions.user.profile'];

    public function __construct(
        private readonly RemoteEventRefresher $refresher,
        private readonly QuotedEventResolver $quotedEvents,
    ) {}

    public function index(Request $request): View
    {
        return $this->browse($request, false);
    }

    public function archive(Request $request): View
    {
        return $this->browse($request, true);
    }

    private function browse(Request $request, bool $archive): View
    {
        $viewer = auth()->user()?->actor;
        $defaultHours = max(1, (int) config('openbook.events.default_duration_hours', 12));
        $activeStatuses = [Event::STATUS_SCHEDULED, Event::STATUS_TENTATIVE, Event::STATUS_POSTPONED];

        $events = Event::query()
            ->with(self::CARD_RELATIONS)
            ->visibleTo($viewer)
            ->where(function (Builder $query) use ($viewer): void {
                $query->where('visibility', Event::VISIBILITY_PUBLIC);

                if ($viewer !== null) {
                    $query->orWhere('visibility', Event::VISIBILITY_FOLLOWERS);
                }
            })
            ->where('status', '!=', Event::STATUS_DELETED)
            ->when(
                $archive,
                fn (Builder $query) => $query->where(function (Builder $query) use ($defaultHours): void {
                    $query->where('status', Event::STATUS_CANCELLED)
                        ->orWhere('end_at', '<=', now())
                        ->orWhere(function (Builder $query) use ($defaultHours): void {
                            $query->whereNull('end_at')->where('start_at', '<=', now()->subHours($defaultHours));
                        });
                })->orderByDesc('start_at'),
                fn (Builder $query) => $query->whereIn('status', $activeStatuses)
                    ->where(function (Builder $query) use ($defaultHours): void {
                        $query->where('end_at', '>', now())
                            ->orWhere(function (Builder $query) use ($defaultHours): void {
                                $query->whereNull('end_at')->where('start_at', '>', now()->subHours($defaultHours));
                            });
                    })->orderBy('start_at')->orderBy('name'),
            )
            ->paginate(18)
            ->withQueryString();

        $yourEvents = collect();

        if ($viewer !== null && ! $archive) {
            $yourEvents = Event::query()
                ->with(self::CARD_RELATIONS)
                ->visibleTo($viewer)
                ->where('status', '!=', Event::STATUS_DELETED)
                ->where(function (Builder $query) use ($viewer): void {
                    $query->where(function (Builder $query) use ($viewer): void {
                        $query->where('visibility', Event::VISIBILITY_DIRECT)
                            ->whereExists(function ($subquery) use ($viewer): void {
                                $subquery->selectRaw('1')->from('event_recipients')
                                    ->whereColumn('event_recipients.event_id', 'events.id')
                                    ->where('event_recipients.actor_id', $viewer->id);
                            });
                    })->orWhereExists(function ($subquery) use ($viewer): void {
                        $subquery->selectRaw('1')->from('likes')
                            ->whereColumn('likes.likeable_id', 'events.id')
                            ->where('likes.likeable_type', (new Event)->getMorphClass())
                            ->where('likes.actor_id', $viewer->id);
                    })->orWhereExists(function ($subquery) use ($viewer): void {
                        $subquery->selectRaw('1')->from('event_participations')
                            ->whereColumn('event_participations.event_id', 'events.id')
                            ->where('event_participations.actor_id', $viewer->id)
                            ->whereIn('event_participations.status', [
                                EventParticipation::STATUS_PENDING,
                                EventParticipation::STATUS_ACCEPTED,
                            ]);
                    });
                })
                ->where(function (Builder $query) use ($defaultHours): void {
                    $query->where('status', Event::STATUS_CANCELLED)
                        ->orWhere('end_at', '>', now())
                        ->orWhere(function (Builder $query) use ($defaultHours): void {
                            $query->whereNull('end_at')->where('start_at', '>', now()->subHours($defaultHours));
                        });
                })
                ->orderBy('start_at')
                ->limit(6)
                ->get();
        }

        return view('events.index', compact('events', 'yourEvents', 'archive'));
    }

    public function show(Event $event): View
    {
        $viewer = auth()->user()?->actor;
        abort_unless(Event::query()->whereKey($event->id)->visibleTo($viewer)->exists(), 404);

        try {
            $this->refresher->refreshIfStale($event, $viewer);
        } catch (\Throwable $exception) {
            report($exception);
        }

        $event->refresh();
        abort_unless(Event::query()->whereKey($event->id)->visibleTo($viewer)->exists(), 404);
        $event->load([...self::CARD_RELATIONS, 'links', 'hashtags']);

        $comments = $event->comments()
            ->with(['actor.user.profile', 'media.thumbnail', 'parent.actor.user.profile'])
            ->get();
        $commentTree = EventCommentThread::tree($comments);
        $eventCommentsCount = $comments->where('status', EventComment::STATUS_PUBLISHED)->count();

        $viewerLike = null;
        $viewerParticipation = null;

        if ($viewer !== null) {
            $viewerLike = $event->likes()->where('actor_id', $viewer->id)->first();
            $viewerParticipation = $event->participations()->where('actor_id', $viewer->id)->first();
        }

        return view('events.show', compact('event', 'viewerLike', 'viewerParticipation', 'commentTree', 'eventCommentsCount'));
    }

    public function shareToUser(Event $event): RedirectResponse
    {
        $viewer = auth()->user()->actor;
        abort_unless($this->quotedEvents->resolveForShare($viewer, $event->id) !== null, 404);

        return redirect()->route('messages.index', ['event' => $event->id]);
    }
}
