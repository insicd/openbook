<?php

namespace App\Federation\Events;

use App\Domain\Events\Event;
use App\Federation\Actors\Actor;
use App\Federation\Actors\RemoteActorResolver;
use App\Federation\Inbox\RemoteEventIngester;
use App\Federation\Inbox\RemoteEventObject;
use App\Federation\Inbox\RemoteNoteDocumentFetcher;
use App\Federation\Inbox\RemotePostObject;

/** Risolve e importa un Event pubblico cercato tramite il suo URL remoto. */
final class RemoteEventUrlResolver
{
    public function __construct(
        private readonly RemoteNoteDocumentFetcher $documents,
        private readonly RemoteActorResolver $actors,
        private readonly RemoteEventIngester $events,
    ) {}

    public function resolve(string $url, ?Actor $viewer = null): ?Event
    {
        $cached = Event::query()
            ->where('uri', $url)
            ->orWhere('url', $url)
            ->first();

        if ($cached !== null
            && Event::query()->whereKey($cached->id)->visibleTo($viewer)->exists()) {
            return $cached;
        }

        $document = $this->documents->fetchDocument($url, $viewer);

        return $this->resolveFetchedDocument($document, $viewer);
    }

    /**
     * @param  array<string, mixed>|null  $document
     */
    public function resolveFetchedDocument(?array $document, ?Actor $viewer = null): ?Event
    {
        $event = is_array($document) ? RemoteEventObject::unwrap($document) : null;

        if ($event === null
            || ! in_array(RemoteEventObject::visibility($event), [Event::VISIBILITY_PUBLIC, Event::VISIBILITY_UNLISTED], true)) {
            return null;
        }

        $actorUri = RemoteEventObject::creatorUri($event)
            ?? RemotePostObject::actorUris($event['attributedTo'] ?? null)[0]
            ?? null;

        if ($actorUri === null) {
            return null;
        }

        $actor = $this->actors->resolveByUri($actorUri);

        if ($actor === null) {
            return null;
        }

        return $this->events->ingest($event, $actor, 'Create', $viewer);
    }
}
