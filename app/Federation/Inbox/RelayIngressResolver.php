<?php

namespace App\Federation\Inbox;

use App\Application\Services\DomainBlockManager;
use App\Domain\Federation\Relay;
use App\Federation\Actors\Actor;

/** Identifica un trasportatore HTTP come relay gia' autorizzato. */
final class RelayIngressResolver
{
    public function __construct(
        private readonly DomainBlockManager $domainBlocks,
    ) {}

    public function resolve(?Actor $transportActor): ?Relay
    {
        if ($transportActor === null
            || $transportActor->isLocal()
            || $this->domainBlocks->isBlockedUrl($transportActor->uri)) {
            return null;
        }

        return Relay::query()
            ->whereIn('protocol', [Relay::PROTOCOL_MASTODON, Relay::PROTOCOL_ACTOR])
            ->where('state', Relay::STATE_ACCEPTED)
            ->where('receive_enabled', true)
            ->where('actor_uri', $transportActor->uri)
            ->first();
    }
}
