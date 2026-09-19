<?php

namespace App\Federation\Inbox;

use App\Domain\Events\Event;
use App\Domain\Events\EventAnnounce;
use App\Domain\Events\EventLink;
use App\Domain\Posts\Hashtag;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Resolution\ObjectResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Salva Event remoti incorporati in Create o Announce verificati. */
final class RemoteEventIngester
{
    public function __construct(
        private readonly ObjectResolver $objects,
        private readonly RemoteAttachmentIngester $attachments,
    ) {}

    /**
     * @param  array<string, mixed>  $document
     */
    public function ingest(array $document, Actor $activityActor, string $activityType, ?Actor $inboxTarget = null): ?Event
    {
        $uri = RemoteEventObject::uri($document);
        $name = RemoteEventObject::name($document);
        $startAt = RemoteEventObject::startAt($document);

        if ($uri === null || $name === null || $startAt === null) {
            return null;
        }

        $existing = $this->objects->resolveEvent($uri);

        if ($existing?->isDeleted()) {
            return null;
        }

        if ($activityType === 'Update' && ($existing === null || ! $this->canManage($existing, $activityActor))) {
            return null;
        }

        $attributionUris = array_slice(RemotePostObject::actorUris($document['attributedTo'] ?? null), 0, 20);
        $declaredCreatorUri = RemoteEventObject::creatorUri($document);

        if ($activityType === 'Create'
            && ! in_array($activityActor->uri, array_filter([$declaredCreatorUri, ...$attributionUris]), true)) {
            return null;
        }

        $creatorUri = $activityType === 'Update' ? $existing?->actor?->uri : $declaredCreatorUri;

        if ($activityType === 'Update' && $declaredCreatorUri !== null && $declaredCreatorUri !== $creatorUri) {
            return null;
        }

        if ($creatorUri === null && $activityType === 'Create') {
            $creatorUri = $activityActor->uri;
        }

        if ($creatorUri === null) {
            $creatorUri = $attributionUris[0] ?? null;
        }

        $creator = $this->resolveRemoteActor($creatorUri, $activityActor);

        if ($creator === null) {
            return null;
        }

        $attributions = $this->resolveAttributions($attributionUris, $activityActor);
        $visibility = RemoteEventObject::visibility($document);
        $recipientIds = $this->recipientIds($document, $visibility, $creator, $attributions, $inboxTarget);

        if (in_array($visibility, [Event::VISIBILITY_DIRECT, Event::VISIBILITY_FOLLOWERS], true)
            && $recipientIds === []
            && $activityType !== 'Update') {
            return null;
        }

        return DB::transaction(function () use (
            $document,
            $activityType,
            $uri,
            $name,
            $startAt,
            $creator,
            $attributions,
            $visibility,
            $recipientIds,
        ): Event {
            $event = $this->objects->resolveEvent($uri) ?? new Event(['uri' => $uri]);
            $shouldRefreshObject = ! $event->exists || in_array($activityType, ['Create', 'Update'], true);
            $incomingUpdatedAt = RemoteEventObject::updatedAt($document);

            if ($shouldRefreshObject
                && $event->remote_updated_at !== null
                && $incomingUpdatedAt !== null
                && $incomingUpdatedAt->lessThan($event->remote_updated_at)) {
                $shouldRefreshObject = false;
            }

            if ($shouldRefreshObject) {
                $participantCount = RemoteEventObject::participantCount($document);
                $likesCount = RemoteEventObject::likesCount($document);
                $endAt = RemoteEventObject::endAt($document, $startAt);

                if (array_key_exists('endTime', $document) && $document['endTime'] !== null && $endAt === null) {
                    Log::channel('single')->debug('federation.event_invalid_end_time', [
                        'event_uri' => $uri,
                    ]);
                }

                $event->fill([
                    'actor_id' => $creator->id,
                    'url' => RemoteEventObject::primaryUrl($document),
                    'name' => $name,
                    'summary' => RemoteEventObject::summary($document),
                    'content' => RemoteEventObject::content($document),
                    'custom_emojis' => RemoteCustomEmoji::extract($document) ?: null,
                    'language' => RemoteEventObject::language($document),
                    'visibility' => $visibility,
                    'status' => RemoteEventObject::status($document),
                    'join_mode' => RemoteEventObject::joinMode($document),
                    'sensitive' => RemoteEventObject::sensitive($document),
                    'is_online' => RemoteEventObject::isOnline($document),
                    'external_participation_url' => RemoteEventObject::externalParticipationUrl($document),
                    'category' => RemoteEventObject::category($document),
                    'start_at' => $startAt,
                    'end_at' => $endAt,
                    'timezone' => RemoteEventObject::timezone($document),
                    'utc_offset_minutes' => RemoteEventObject::utcOffsetMinutes($document),
                    'series_uri' => RemoteEventObject::seriesUri($document),
                    'participant_count' => $participantCount,
                    'likes_count' => $likesCount,
                    'remote_counts_fetched_at' => $participantCount !== null || $likesCount !== null ? now() : null,
                    'published_at' => RemoteEventObject::publishedAt($document),
                    'remote_updated_at' => $incomingUpdatedAt ?? $event->remote_updated_at,
                    'deleted_at' => null,
                ]);
                $event->save();

                $event->attributions()->sync($this->attributionSyncData($attributions));
                $event->recipients()->syncWithoutDetaching($recipientIds);
                $this->syncHashtags($event, $document);
                $this->syncLocation($event, $document);
                $this->syncLinks($event, $document);
                $this->attachments->sync($event, $creator, $document);
            } elseif ($recipientIds !== []) {
                // Lo stesso oggetto non pubblico puo' essere consegnato a piu'
                // inbox locali in attivita' distinte: ogni consegna aggiunge
                // un grant senza revocare quelli registrati in precedenza.
                $event->recipients()->syncWithoutDetaching($recipientIds);
            }

            return $event;
        });
    }

    /** @param array<string, mixed> $activity */
    public function recordAnnounce(Event $event, Actor $actor, array $activity): EventAnnounce
    {
        $uri = isset($activity['id']) && is_string($activity['id']) ? $activity['id'] : null;
        $publishedAt = RemoteEventObject::publishedAt($activity);

        return EventAnnounce::query()->updateOrCreate(
            [
                'event_id' => $event->id,
                'actor_id' => $actor->id,
            ],
            [
                'uri' => $uri,
                'published_at' => $publishedAt,
            ],
        );
    }

    public function undoAnnounce(Event $event, Actor $actor): void
    {
        EventAnnounce::query()
            ->where('event_id', $event->id)
            ->where('actor_id', $actor->id)
            ->delete();
    }

    public function canManage(Event $event, Actor $actor): bool
    {
        return $event->actor_id === $actor->id
            || $event->attributions()->whereKey($actor->id)->exists();
    }

    private function resolveRemoteActor(?string $uri, Actor $activityActor): ?Actor
    {
        if ($uri === null) {
            return null;
        }

        $actor = $uri === $activityActor->uri ? $activityActor : $this->objects->resolveActor($uri);

        return $actor !== null && ! $actor->isLocal() ? $actor : null;
    }

    /**
     * @param  list<string>  $uris
     * @return list<Actor>
     */
    private function resolveAttributions(array $uris, Actor $activityActor): array
    {
        $actors = [];

        foreach ($uris as $uri) {
            $actor = $this->resolveRemoteActor($uri, $activityActor);

            if ($actor !== null) {
                $actors[$actor->id] = $actor;
            }
        }

        return array_values($actors);
    }

    /**
     * @param  list<Actor>  $attributions
     * @return list<string>
     */
    private function recipientIds(
        array $document,
        string $visibility,
        Actor $creator,
        array $attributions,
        ?Actor $inboxTarget,
    ): array {
        if (in_array($visibility, [Event::VISIBILITY_PUBLIC, Event::VISIBILITY_UNLISTED], true)) {
            return [];
        }

        $ids = Actor::query()
            ->where('is_local', true)
            ->where('status', Actor::STATUS_ACTIVE)
            ->whereIn('uri', RemoteEventObject::audienceActorUris($document))
            ->pluck('id')
            ->all();

        if ($inboxTarget !== null && $inboxTarget->isLocal() && $inboxTarget->isActive()) {
            $ids[] = $inboxTarget->id;
        }

        if ($visibility === Event::VISIBILITY_FOLLOWERS) {
            $managerIds = array_values(array_unique([
                $creator->id,
                ...array_map(static fn (Actor $actor): string => $actor->id, $attributions),
            ]));

            $followerIds = Follow::query()
                ->whereIn('following_id', $managerIds)
                ->where('status', Follow::STATUS_ACCEPTED)
                ->whereIn('follower_id', Actor::query()->select('id')->where('is_local', true)->where('status', Actor::STATUS_ACTIVE))
                ->pluck('follower_id')
                ->all();

            $ids = array_merge($ids, $followerIds);
        }

        return array_values(array_unique($ids));
    }

    /** @param list<Actor> $actors @return array<string, array{position: int}> */
    private function attributionSyncData(array $actors): array
    {
        $sync = [];

        foreach ($actors as $position => $actor) {
            $sync[$actor->id] = ['position' => $position];
        }

        return $sync;
    }

    /** @param array<string, mixed> $document */
    private function syncHashtags(Event $event, array $document): void
    {
        $ids = [];

        foreach (RemoteEventObject::hashtags($document) as $name) {
            $ids[] = Hashtag::query()->firstOrCreate(['name' => $name])->id;
        }

        $event->hashtags()->sync($ids);
    }

    /** @param array<string, mixed> $document */
    private function syncLocation(Event $event, array $document): void
    {
        $location = RemoteEventObject::location($document);

        if ($location === null) {
            $event->location()->delete();

            return;
        }

        $event->location()->updateOrCreate([], $location);
    }

    /** @param array<string, mixed> $document */
    private function syncLinks(Event $event, array $document): void
    {
        $event->links()->delete();

        foreach (RemoteEventObject::links($document) as $position => $link) {
            EventLink::query()->create([
                'event_id' => $event->id,
                'position' => $position,
                ...$link,
            ]);
        }
    }
}
