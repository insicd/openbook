<?php

namespace App\Application\Queries;

use App\Domain\Events\Event;
use App\Federation\Actors\Actor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class ActorEventsQuery
{
    public function forActor(Actor $actor, ?Actor $viewer, bool $archive, int $perPage = 18): LengthAwarePaginator
    {
        $defaultHours = max(1, (int) config('openbook.events.default_duration_hours', 12));
        $activeStatuses = [Event::STATUS_SCHEDULED, Event::STATUS_TENTATIVE, Event::STATUS_POSTPONED];

        return Event::query()
            ->with(['actor.user.profile', 'location', 'media.thumbnail', 'attributions.user.profile'])
            ->visibleTo($viewer)
            ->where(function (Builder $query) use ($actor): void {
                $query->where('actor_id', $actor->id)
                    ->orWhereHas('attributions', fn (Builder $query) => $query->whereKey($actor->id));
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
            ->paginate($perPage)
            ->withQueryString();
    }
}
