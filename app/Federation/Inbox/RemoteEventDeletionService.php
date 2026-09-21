<?php

namespace App\Federation\Inbox;

use App\Domain\Events\Event;
use App\Federation\Actors\Actor;
use App\Infrastructure\Media\Media;
use Illuminate\Support\Facades\DB;

/** Trasforma eventi remoti cancellati in tombstone non ripristinabili. */
final class RemoteEventDeletionService
{
    public function delete(Event $event): bool
    {
        return DB::transaction(function () use ($event): bool {
            $locked = Event::query()->lockForUpdate()->find($event->id);

            if ($locked === null || $locked->isDeleted()) {
                return false;
            }

            $this->tombstone($locked);

            return true;
        });
    }

    /** Invalida gli eventi creati dall'Actor e lo sgancia da quelli distribuiti. */
    public function deleteActorEvents(Actor $actor): void
    {
        Event::query()
            ->where('actor_id', $actor->id)
            ->where('status', '!=', Event::STATUS_DELETED)
            ->eachById(fn (Event $event) => $this->tombstone($event));

        DB::table('event_attributions')->where('actor_id', $actor->id)->delete();
        DB::table('event_announces')->where('actor_id', $actor->id)->delete();
        DB::table('event_participations')->where('actor_id', $actor->id)->delete();
    }

    private function tombstone(Event $event): void
    {
        $mediaIds = $event->media()->pluck('media.id')
            ->merge(
                Media::query()
                    ->whereHas('eventComments', fn ($query) => $query->where('event_id', $event->id))
                    ->pluck('id'),
            )
            ->unique();

        $event->location()->delete();
        $event->hashtags()->detach();
        $event->attachments()->delete();
        $event->links()->delete();
        $event->announces()->delete();
        $event->participations()->delete();
        $event->likes()->delete();
        $event->comments()->delete();

        if ($mediaIds->isNotEmpty()) {
            Media::query()
                ->whereIn('id', $mediaIds)
                ->whereNotNull('remote_url')
                ->whereDoesntHave('posts')
                ->whereDoesntHave('comments')
                ->whereDoesntHave('events')
                ->whereDoesntHave('eventComments')
                ->delete();
        }

        $event->forceFill([
            'url' => null,
            'name' => '',
            'summary' => null,
            'content' => null,
            'custom_emojis' => null,
            'language' => null,
            'status' => Event::STATUS_DELETED,
            'join_mode' => null,
            'sensitive' => false,
            'is_online' => false,
            'external_participation_url' => null,
            'category' => null,
            'end_at' => null,
            'timezone' => null,
            'utc_offset_minutes' => null,
            'series_uri' => null,
            'participant_count' => null,
            'likes_count' => null,
            'remote_counts_fetched_at' => null,
            'remote_updated_at' => null,
            'deleted_at' => now(),
        ])->save();
    }
}
