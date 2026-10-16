<?php

namespace App\Federation\Polls;

use App\Application\Services\DomainBlockManager;
use App\Application\Services\LocalPollVoter;
use App\Federation\Actors\Actor;
use App\Federation\Inbox\InboxItem;
use App\Federation\Inbox\RemotePostObject;
use App\Federation\Resolution\ObjectResolver;
use App\Federation\Serialization\NoteSerializer;
use App\Federation\Support\ActivityPubUri;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

final class LocalPollVoteIngester
{
    public function __construct(
        private readonly ObjectResolver $objects,
        private readonly LocalPollVoter $voter,
        private readonly DomainBlockManager $blocks,
    ) {}

    /**
     * @param  array<string, mixed>  $activity
     * @param  array<string, mixed>  $note
     */
    public function ingest(array $activity, array $note, Actor $signer, InboxItem $source, bool $fromRelay): string
    {
        $uri = $note['id'] ?? null;
        if ($fromRelay || ! $source->signature_valid || $source->activity_type !== 'Create'
            || ! RemotePostObject::hasType($activity['type'] ?? null, 'Create')
            || ! is_string($activity['actor'] ?? null) || ! ActivityPubUri::same($activity['actor'], $signer->uri)
            || ! is_string($note['attributedTo'] ?? null) || ! ActivityPubUri::same($note['attributedTo'], $signer->uri)
            || ! is_string($uri) || ! $this->sameOrigin($uri, $signer->uri)
            || $this->blocks->isBlockedUrl($uri) || $source->received_at === null) {
            return InboxItem::STATUS_IGNORED;
        }
        $object = $activity['object'] ?? null;
        if (is_string($object) && ! ActivityPubUri::same($object, $uri)) {
            return InboxItem::STATUS_IGNORED;
        }
        $target = RemotePostObject::inReplyToTarget($note);
        $post = $target !== null ? $this->objects->resolvePost($target) : null;
        if ($post === null || $post->isRemote() || ! ActivityPubUri::same(NoteSerializer::postUri($post), $target)
            || $post->poll === null || $source->received_at->lt($post->published_at)
            || $source->received_at->gt(now()) || ($source->target_actor_id !== null && $source->target_actor_id !== $post->actor_id)) {
            return InboxItem::STATUS_IGNORED;
        }

        // A dereferenced document is a snapshot observed now, not at envelope receipt.
        // Require both instants before expiry; never credit late mutations to an old URI.
        $receivedAt = $source->received_at;
        if (is_string($object) && now()->gt($receivedAt)) {
            $receivedAt = now();
        }
        try {
            return $this->voter->receive($signer, $post->poll, $note['name'], $uri, $receivedAt) !== null
                ? InboxItem::STATUS_PROCESSED : InboxItem::STATUS_IGNORED;
        } catch (AuthorizationException|ModelNotFoundException|ValidationException) {
            return InboxItem::STATUS_IGNORED;
        }
    }

    private function sameOrigin(string $uri, string $actorUri): bool
    {
        if (filter_var($uri, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($uri);
        if (isset($parts['user']) || isset($parts['pass']) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }
        $origin = static fn (string $value): string => ActivityPubUri::normalize(
            (string) parse_url($value, PHP_URL_SCHEME).'://'.(string) parse_url($value, PHP_URL_HOST)
            .(parse_url($value, PHP_URL_PORT) !== null ? ':'.parse_url($value, PHP_URL_PORT) : '')
        );

        return $origin($uri) === $origin($actorUri);
    }
}
