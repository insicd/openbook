<?php

namespace App\Federation\Actors;

use App\Application\Services\AccountMoveNotifier;
use App\Federation\Inbox\InboxItem;
use App\Federation\Support\ActivityPubUri;
use Illuminate\Support\Facades\DB;

final class RemoteAccountMoveHandler
{
    public function __construct(
        private readonly AccountMoveTargetResolver $targets,
        private readonly AccountMoveNotifier $notifications,
    ) {}

    /** @param array<string, mixed> $activity */
    public function handle(array $activity, Actor $signer): string
    {
        $actorUri = $this->objectId($activity['actor'] ?? null);
        $objectUri = $this->objectId($activity['object'] ?? null);
        $targetUri = $this->objectId($activity['target'] ?? null);

        if ($signer->isLocal() || ! $signer->isActive() || $signer->isRemotelySuspended()
            || (! $signer->isPerson() && ! $signer->isApplication())
            || $actorUri === null || ! ActivityPubUri::same($actorUri, $signer->uri)
            || $objectUri === null || ! ActivityPubUri::same($objectUri, $signer->uri)
            || $targetUri === null
        ) {
            return InboxItem::STATUS_IGNORED;
        }

        // Remote aliases require a successful fresh fetch; local aliases are read
        // directly from the database and verified again under lock.
        $target = $this->targets->resolve($signer->uri, $targetUri);
        if ($target === null) {
            return InboxItem::STATUS_IGNORED;
        }

        return DB::transaction(function () use ($signer, $target, $targetUri): string {
            $source = Actor::query()->lockForUpdate()->findOrFail($signer->id);
            if ($target->isLocal()) {
                // Serialize verification with alias edits; never trust a stale local alias.
                $target = Actor::query()->with('user')->lockForUpdate()->find($target->id);
                if ($target === null || ! $this->targets->isValid($target, $source->uri, $targetUri)) {
                    return InboxItem::STATUS_IGNORED;
                }
            }
            if ($source->moved_to_actor_id !== null && $source->moved_to_actor_id !== $target->id) {
                return InboxItem::STATUS_IGNORED;
            }

            $this->notifications->notifyFollowers($source, $target);

            $source->forceFill(['moved_to_actor_id' => $target->id])->save();

            return InboxItem::STATUS_PROCESSED;
        });
    }

    private function objectId(mixed $value): ?string
    {
        $id = is_array($value) ? ($value['id'] ?? null) : $value;

        return is_string($id) && filter_var($id, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($id, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $id
            : null;
    }
}
