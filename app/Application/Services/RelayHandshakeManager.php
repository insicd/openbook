<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Domain\Federation\Relay;
use App\Federation\Actors\Actor;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\RelayActivitySerializer;
use App\Federation\Support\ActivityPubUri;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RelayHandshakeManager
{
    public function __construct(
        private readonly InstanceRelayActor $instanceRelayActor,
        private readonly ActivityDelivery $delivery,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function subscribe(User $admin, Relay $relay): Relay
    {
        $this->assertAdmin($admin);

        if ($relay->protocol !== Relay::PROTOCOL_MASTODON) {
            throw new InvalidArgumentException(__('openbook.admin.relays.unsupported_protocol'));
        }

        if ($relay->state === Relay::STATE_ACCEPTED) {
            return $relay;
        }

        $actor = $this->instanceRelayActor->getOrCreate();
        $followActivityUri = RelayActivitySerializer::newFollowActivityUri();
        $activity = RelayActivitySerializer::follow($relay, $actor, $followActivityUri);

        $relay->forceFill([
            'follow_activity_uri' => $followActivityUri,
            'state' => Relay::STATE_PENDING,
            'last_error' => null,
            'accepted_at' => null,
        ])->save();

        $this->delivery->deliverToInbox($actor, $relay->inbox_url, $activity, $relay->id);
        $this->auditLogger->log($admin, 'relay.subscribe', $relay, [
            'host' => parse_url($relay->inbox_url, PHP_URL_HOST),
        ]);

        return $relay->refresh();
    }

    public function unsubscribe(User $admin, Relay $relay): Relay
    {
        $this->assertAdmin($admin);

        $shouldNotify = in_array($relay->state, [
            Relay::STATE_PENDING,
            Relay::STATE_ACCEPTED,
            Relay::STATE_REJECTED,
            Relay::STATE_FAILED,
        ], true) && filled($relay->follow_activity_uri);

        $actor = $shouldNotify ? $this->instanceRelayActor->getOrCreate() : null;
        $activity = $actor !== null ? RelayActivitySerializer::undoFollow($relay, $actor) : null;

        DB::transaction(function () use ($relay): void {
            $relay->forceFill([
                'state' => Relay::STATE_IDLE,
                'follow_activity_uri' => null,
                'accepted_at' => null,
                'last_error' => null,
            ])->save();
        });

        if ($actor !== null && $activity !== null) {
            $this->delivery->deliverToInbox($actor, $relay->inbox_url, $activity, $relay->id);
        }

        $this->auditLogger->log($admin, 'relay.unsubscribe', $relay, [
            'host' => parse_url($relay->inbox_url, PHP_URL_HOST),
        ]);

        return $relay->refresh();
    }

    /**
     * Applica un Accept/Reject gia' autenticato dal normale ingresso inbox.
     * Restituisce true soltanto quando l'attivita' appartiene a un handshake
     * relay noto; gli altri Accept/Reject proseguono nel grafo Follow normale.
     *
     * @param  array<string, mixed>  $activity
     */
    public function receiveResponse(array $activity, Actor $remoteActor, bool $accepted): bool
    {
        $followUri = $this->objectId($activity['object'] ?? null);

        if ($followUri === null) {
            return false;
        }

        $relay = Relay::query()
            ->where('follow_activity_uri', $followUri)
            ->first();

        if ($relay === null || $relay->state !== Relay::STATE_PENDING) {
            return false;
        }

        if (! $this->responseActorMatchesRelay($relay, $remoteActor)) {
            return false;
        }

        if (is_array($activity['object'] ?? null) && ! $this->embeddedFollowMatches($activity['object'])) {
            return false;
        }

        $relay->forceFill([
            'actor_uri' => $remoteActor->uri,
            'state' => $accepted ? Relay::STATE_ACCEPTED : Relay::STATE_REJECTED,
            'accepted_at' => $accepted ? now() : null,
            'last_success_at' => now(),
            'last_failure_at' => null,
            'last_error' => null,
        ])->save();

        return true;
    }

    private function responseActorMatchesRelay(Relay $relay, Actor $remoteActor): bool
    {
        if ($remoteActor->isLocal()) {
            return false;
        }

        if (filled($relay->actor_uri)) {
            return ActivityPubUri::same($relay->actor_uri, $remoteActor->uri);
        }

        $inboxHost = parse_url($relay->inbox_url, PHP_URL_HOST);
        $actorHost = parse_url($remoteActor->uri, PHP_URL_HOST);

        return is_string($inboxHost)
            && is_string($actorHost)
            && strcasecmp($inboxHost, $actorHost) === 0;
    }

    /** @param array<string, mixed> $follow */
    private function embeddedFollowMatches(array $follow): bool
    {
        if (($follow['type'] ?? null) !== 'Follow') {
            return false;
        }

        $actorUri = $this->objectId($follow['actor'] ?? null);
        $objectUri = $this->objectId($follow['object'] ?? null);

        return $actorUri !== null
            && ActivityPubUri::same($actorUri, $this->instanceRelayActor->getOrCreate()->activityPubId())
            && $objectUri === RelayActivitySerializer::PUBLIC_STREAM;
    }

    private function objectId(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return is_array($value) && is_string($value['id'] ?? null) && $value['id'] !== ''
            ? $value['id']
            : null;
    }

    private function assertAdmin(User $admin): void
    {
        if (! $admin->canAdminister()) {
            throw new InvalidArgumentException(__('openbook.admin.relays.admin_required'));
        }
    }
}
