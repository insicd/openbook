<?php

namespace App\Federation\Actors;

use App\Application\Services\DomainBlockManager;
use App\Federation\Support\ActivityPubUri;

/** Resolve verified Move targets without changing informational profile discovery. */
final class AccountMoveTargetResolver
{
    public function __construct(
        private readonly RemoteActorResolver $remotes,
        private readonly LocalActorResolver $locals,
        private readonly DomainBlockManager $blocks,
    ) {}

    public function resolve(string $sourceUri, string $targetUri): ?Actor
    {
        if (strlen($targetUri) > 2048 || filter_var($targetUri, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($targetUri, PHP_URL_SCHEME)), ['http', 'https'], true)
            || parse_url($targetUri, PHP_URL_USER) !== null || parse_url($targetUri, PHP_URL_PASS) !== null
            || ActivityPubUri::same($sourceUri, $targetUri) || $this->blocks->isBlockedUrl($targetUri)) {
            return null;
        }

        $host = strtolower((string) parse_url($targetUri, PHP_URL_HOST));
        $localHosts = [strtolower((string) parse_url(url('/'), PHP_URL_HOST)),
            strtolower((string) parse_url('//'.config('openbook.domain'), PHP_URL_HOST))];
        if (in_array($host, $localHosts, true)) {
            $path = (string) parse_url(ActivityPubUri::normalize($targetUri), PHP_URL_PATH);
            $prefix = preg_quote((string) parse_url(url('/users'), PHP_URL_PATH), '~');
            if (preg_match('~^'.$prefix.'/([A-Za-z0-9_]+)$~D', $path, $matches) !== 1) {
                return null;
            }
            $target = $this->locals->findByUsername($matches[1]);

            return $target !== null && $target->isLocal() && $this->isValid($target, $sourceUri, $targetUri)
                ? $target : null;
        }

        $target = $this->remotes->resolveMovedTo($sourceUri, $targetUri, forceRefresh: true);

        return $target !== null && $this->isValid($target, $sourceUri, $targetUri) ? $target : null;
    }

    public function isValid(Actor $target, string $sourceUri, string $targetUri): bool
    {
        return $target->isActive() && ! $target->isRemotelySuspended()
            && ($target->isLocal() ? ($target->isPerson() && $target->user?->isActive()) : ($target->isPerson() || $target->isApplication()))
            && $target->moved_to_actor_id === null
            && ActivityPubUri::same($target->activityPubId(), $targetUri)
            && ! ActivityPubUri::same($sourceUri, $targetUri)
            && ! $this->blocks->isBlockedUrl($targetUri)
            && collect($target->also_known_as)->contains(fn (string $alias): bool => ActivityPubUri::same($alias, $sourceUri));
    }
}
