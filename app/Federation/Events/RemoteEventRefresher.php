<?php

namespace App\Federation\Events;

use App\Domain\Events\Event;
use App\Federation\Actors\Actor;
use App\Federation\Inbox\RemoteEventIngester;
use App\Federation\Inbox\RemoteEventObject;
use App\Federation\Inbox\RemoteNoteDocumentFetcher;
use App\Federation\Support\ActivityPubUri;

/** Aggiorna opportunisticamente un Event remoto quando la cache è scaduta. */
final class RemoteEventRefresher
{
    public function __construct(
        private readonly RemoteNoteDocumentFetcher $documents,
        private readonly RemoteEventIngester $events,
    ) {}

    public function refreshIfStale(Event $event, ?Actor $viewer = null): bool
    {
        $ttl = max(1, (int) config('openbook.events.cache_ttl_hours', 4));

        if (! $event->isRemote()
            || $event->isDeleted()
            || $event->remote_counts_fetched_at?->greaterThan(now()->subHours($ttl))) {
            return false;
        }

        $event->forceFill(['remote_counts_fetched_at' => now()])->save();
        $document = $this->documents->fetchDocument($event->uri, $viewer);
        $eventDocument = $document !== null ? RemoteEventObject::unwrap($document) : null;

        if ($eventDocument === null
            || RemoteEventObject::uri($eventDocument) === null
            || ActivityPubUri::normalize(RemoteEventObject::uri($eventDocument)) !== ActivityPubUri::normalize($event->uri)
            || $event->actor === null) {
            return false;
        }

        $updated = $this->events->ingest($eventDocument, $event->actor, 'Update', $viewer);
        $updated?->forceFill(['remote_counts_fetched_at' => now()])->save();

        return $updated !== null;
    }
}
