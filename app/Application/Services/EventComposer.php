<?php

namespace App\Application\Services;

use App\Domain\Events\Event;
use App\Domain\Events\EventAttachment;
use App\Domain\Events\EventLocation;
use App\Domain\Locations\GeoCity;
use App\Domain\Posts\ContentParser;
use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Mention;
use App\Federation\Actors\Actor;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\ActivitySerializer;
use App\Infrastructure\Media\Media;
use App\Infrastructure\Media\MediaUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/** Crea un Event locale senza ancora consegnarlo in federazione. */
final class EventComposer
{
    public function __construct(
        private readonly MediaUploader $mediaUploader,
        private readonly ContentParser $contentParser,
        private readonly ActivityDelivery $delivery,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function compose(Actor $author, array $data): Event
    {
        $storedFiles = [];

        try {
            $event = DB::transaction(function () use ($author, $data, &$storedFiles): Event {
                $timezone = (string) $data['timezone'];
                $startsAt = Carbon::createFromFormat('Y-m-d\TH:i', (string) $data['start_at'], $timezone);
                $endsAt = filled($data['end_at'] ?? null)
                    ? Carbon::createFromFormat('Y-m-d\TH:i', (string) $data['end_at'], $timezone)
                    : null;
                $applicationTimezone = (string) config('app.timezone', 'UTC');
                $event = new Event;
                $event->id = $event->newUniqueId();
                $event->forceFill([
                    'actor_id' => $author->id,
                    'uri' => route('events.show', $event->id),
                    'url' => route('events.show', $event->id),
                    'name' => trim((string) $data['name']),
                    'content' => trim((string) $data['content']),
                    'visibility' => $data['visibility'],
                    'status' => Event::STATUS_SCHEDULED,
                    'join_mode' => $data['join_mode'],
                    'sensitive' => (bool) ($data['sensitive'] ?? false),
                    'is_online' => in_array($data['mode'], ['online', 'hybrid'], true),
                    'external_participation_url' => filled($data['participation_url'] ?? null)
                        ? trim((string) $data['participation_url'])
                        : null,
                    'start_at' => $startsAt->copy()->setTimezone($applicationTimezone),
                    'end_at' => $endsAt?->copy()->setTimezone($applicationTimezone),
                    'timezone' => $timezone,
                    'utc_offset_minutes' => (int) ($startsAt->getOffset() / 60),
                    'participant_count' => 0,
                    'likes_count' => 0,
                    'published_at' => now(),
                ])->save();

                $this->syncLocation($event, $data);
                $this->syncHashtags($event);
                $this->syncMentions($event, $author);

                $cover = $data['cover'] ?? null;

                if ($cover instanceof UploadedFile) {
                    $media = $this->mediaUploader->store($cover, $author, $data['cover_alt'] ?? null);
                    $this->rememberFiles($media, $storedFiles);
                    EventAttachment::query()->create([
                        'event_id' => $event->id,
                        'media_id' => $media->id,
                        'position' => 0,
                    ]);
                }

                return $event->fresh(['actor', 'location', 'hashtags', 'mentions.actor', 'media.thumbnail']) ?? $event;
            });

            if ($author->isLocal()) {
                $this->delivery->deliverContent($event, ActivitySerializer::create($event));
            }

            return $event;
        } catch (Throwable $exception) {
            foreach ($storedFiles as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function update(Actor $author, Event $event, array $data): Event
    {
        $this->ensureOwner($author, $event);
        $event->loadMissing('mentions.actor');
        $previousMentionTargets = $event->mentions->pluck('actor')->filter()->values()->all();
        $storedFiles = [];
        $replacedMedia = collect();

        try {
            $event = DB::transaction(function () use ($author, $event, $data, &$storedFiles, &$replacedMedia): Event {
                [$startsAt, $endsAt, $timezone] = $this->dates($data);
                $event->update([
                    'name' => trim((string) $data['name']),
                    'content' => trim((string) $data['content']),
                    'visibility' => $data['visibility'],
                    'join_mode' => $data['join_mode'],
                    'sensitive' => (bool) ($data['sensitive'] ?? false),
                    'is_online' => in_array($data['mode'], ['online', 'hybrid'], true),
                    'external_participation_url' => filled($data['participation_url'] ?? null)
                        ? trim((string) $data['participation_url'])
                        : null,
                    'start_at' => $startsAt,
                    'end_at' => $endsAt,
                    'timezone' => $timezone,
                    'utc_offset_minutes' => (int) (Carbon::createFromFormat('Y-m-d\TH:i', (string) $data['start_at'], $timezone)->getOffset() / 60),
                ]);

                $this->syncLocation($event, $data);
                $this->syncHashtags($event);
                $event->mentions()->delete();
                $this->syncMentions($event, $author);

                $cover = $data['cover'] ?? null;

                if ($cover instanceof UploadedFile) {
                    $replacedMedia = $event->media()->get();
                    $event->attachments()->delete();
                    $media = $this->mediaUploader->store($cover, $author, $data['cover_alt'] ?? null);
                    $this->rememberFiles($media, $storedFiles);
                    EventAttachment::query()->create([
                        'event_id' => $event->id,
                        'media_id' => $media->id,
                        'position' => 0,
                    ]);
                } elseif (array_key_exists('cover_alt', $data)) {
                    $event->media()->update(['alt_text' => $data['cover_alt'] ?: null]);
                }

                return $event->fresh(['actor', 'location', 'hashtags', 'mentions.actor', 'media.thumbnail']) ?? $event;
            });
        } catch (Throwable $exception) {
            foreach ($storedFiles as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }

            throw $exception;
        }

        $replacedMedia->each(fn (Media $media) => $this->deleteMediaIfOrphaned($media));
        $this->delivery->deliverContent($event, ActivitySerializer::update($event), $previousMentionTargets);

        return $event;
    }

    public function cancel(Actor $author, Event $event): Event
    {
        $this->ensureOwner($author, $event);

        if ($event->status === Event::STATUS_CANCELLED) {
            return $event;
        }

        $event->update(['status' => Event::STATUS_CANCELLED]);
        $event = $event->fresh(['actor', 'location', 'hashtags', 'mentions.actor', 'media.thumbnail']) ?? $event;
        $this->delivery->deliverContent($event, ActivitySerializer::update($event));

        return $event;
    }

    public function delete(Actor $author, Event $event): Event
    {
        $this->ensureOwner($author, $event);
        $event->loadMissing('mentions.actor');
        $directTargets = $event->mentions->pluck('actor')->filter()->values()->all();
        $comments = $event->comments()->with('media')->get();
        $media = $event->media()->get()
            ->concat($comments->flatMap->media)
            ->unique('id');

        DB::transaction(function () use ($event, $comments): void {
            $event->attachments()->delete();
            $event->location()->delete();
            $event->hashtags()->detach();
            $event->mentions()->delete();
            $event->links()->delete();
            $event->attributions()->detach();
            $event->recipients()->detach();
            $event->announces()->delete();
            $event->participations()->delete();
            $event->likes()->delete();
            $comments->each(fn ($comment) => $comment->attachments()->delete());
            $event->comments()->delete();
            $event->update([
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
                'series_uri' => null,
                'participant_count' => 0,
                'likes_count' => 0,
                'deleted_at' => now(),
            ]);
        });

        $event = $event->fresh(['actor']) ?? $event;
        $media->each(fn (Media $item) => $this->deleteMediaIfOrphaned($item));
        $this->delivery->deliverContent($event, ActivitySerializer::delete($event), $directTargets);

        return $event;
    }

    /** @param array<string, mixed> $data */
    private function syncLocation(Event $event, array $data): void
    {
        $event->location()->delete();

        if ($data['mode'] === 'online') {
            return;
        }

        $city = filled($data['location_id'] ?? null)
            ? GeoCity::query()->findOrFail((int) $data['location_id'])
            : null;

        EventLocation::query()->create([
            'event_id' => $event->id,
            'geo_city_id' => $city?->geoname_id,
            'name' => filled($data['venue'] ?? null) ? trim((string) $data['venue']) : null,
            'address' => filled($data['address'] ?? null) ? trim((string) $data['address']) : null,
            'street_address' => filled($data['address'] ?? null) ? trim((string) $data['address']) : null,
            'locality' => $city?->name,
            'region' => $city?->admin1_name,
            'country_code' => $city?->country_code,
            'country_name' => $city?->country_name,
            'latitude' => $city?->latitude,
            'longitude' => $city?->longitude,
            'source' => 'local',
        ]);
    }

    private function syncHashtags(Event $event): void
    {
        $ids = $this->contentParser->extractHashtagNames((string) $event->content)
            ->map(fn (string $name) => Hashtag::query()->firstOrCreate(['name' => $name])->id);

        $event->hashtags()->sync($ids);
    }

    private function syncMentions(Event $event, Actor $author): void
    {
        foreach ($this->contentParser->extractMentionedActors((string) $event->content) as $actor) {
            if ($actor->id === $author->id) {
                continue;
            }

            Mention::query()->firstOrCreate([
                'mentionable_type' => $event->getMorphClass(),
                'mentionable_id' => $event->id,
                'actor_id' => $actor->id,
            ]);
        }
    }

    /** @param list<array{0: string, 1: string}> $storedFiles */
    private function rememberFiles(Media $media, array &$storedFiles): void
    {
        $storedFiles[] = [$media->disk, $media->path];
        $thumbnail = $media->thumbnail()->first();

        if ($thumbnail !== null) {
            $storedFiles[] = [$thumbnail->disk, $thumbnail->path];
        }
    }

    /** @param array<string, mixed> $data @return array{0: Carbon, 1: ?Carbon, 2: string} */
    private function dates(array $data): array
    {
        $timezone = (string) $data['timezone'];
        $startsAt = Carbon::createFromFormat('Y-m-d\TH:i', (string) $data['start_at'], $timezone);
        $endsAt = filled($data['end_at'] ?? null)
            ? Carbon::createFromFormat('Y-m-d\TH:i', (string) $data['end_at'], $timezone)
            : null;
        $applicationTimezone = (string) config('app.timezone', 'UTC');

        return [
            $startsAt->copy()->setTimezone($applicationTimezone),
            $endsAt?->copy()->setTimezone($applicationTimezone),
            $timezone,
        ];
    }

    private function ensureOwner(Actor $author, Event $event): void
    {
        if (! $author->isLocal() || $event->actor_id !== $author->id || $event->isRemote() || $event->isDeleted()) {
            throw new InvalidArgumentException('Puoi modificare solo i tuoi eventi locali.');
        }
    }

    private function deleteMediaIfOrphaned(Media $media): void
    {
        $media->refresh();

        if ($media->posts()->exists()
            || $media->comments()->exists()
            || $media->events()->exists()
            || $media->eventComments()->exists()) {
            return;
        }

        $media->loadMissing('thumbnail');
        $paths = [[$media->disk, $media->path]];

        if ($media->thumbnail !== null) {
            $paths[] = [$media->thumbnail->disk, $media->thumbnail->path];
        }

        $media->delete();

        if (! $media->isRemote()) {
            foreach ($paths as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }
        }
    }
}
