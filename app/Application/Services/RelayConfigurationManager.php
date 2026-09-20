<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Domain\Federation\Relay;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RelayConfigurationManager
{
    public function __construct(
        private readonly RelayEndpointNormalizer $normalizer,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @param array{protocol: string, inbox_url: string, receive_enabled?: bool, publish_enabled?: bool} $data */
    public function create(User $admin, array $data): Relay
    {
        if (! $admin->canAdminister()) {
            throw new InvalidArgumentException(__('openbook.admin.relays.admin_required'));
        }

        if ($data['protocol'] !== Relay::PROTOCOL_MASTODON) {
            throw new InvalidArgumentException(__('openbook.admin.relays.unsupported_protocol'));
        }

        $inboxUrl = $this->normalizer->normalize($data['inbox_url']);
        $hash = hash('sha256', $inboxUrl);

        if (Relay::query()->where('inbox_url_hash', $hash)->exists()) {
            throw new InvalidArgumentException(__('openbook.admin.relays.duplicate'));
        }

        $relay = Relay::query()->create([
            'protocol' => Relay::PROTOCOL_MASTODON,
            'inbox_url' => $inboxUrl,
            'inbox_url_hash' => $hash,
            'state' => Relay::STATE_IDLE,
            'receive_enabled' => (bool) ($data['receive_enabled'] ?? false),
            'publish_enabled' => (bool) ($data['publish_enabled'] ?? false),
        ]);

        $this->auditLogger->log($admin, 'relay.create', $relay, [
            'protocol' => $relay->protocol,
            'host' => parse_url($relay->inbox_url, PHP_URL_HOST),
        ]);

        return $relay;
    }

    public function delete(User $admin, Relay $relay): void
    {
        if (! $admin->canAdminister()) {
            throw new InvalidArgumentException(__('openbook.admin.relays.admin_required'));
        }

        if ($relay->state !== Relay::STATE_IDLE) {
            throw new InvalidArgumentException(__('openbook.admin.relays.cannot_delete_active'));
        }

        DB::transaction(function () use ($admin, $relay): void {
            $meta = [
                'protocol' => $relay->protocol,
                'host' => parse_url($relay->inbox_url, PHP_URL_HOST),
            ];
            $relay->delete();
            $this->auditLogger->log($admin, 'relay.delete', null, $meta);
        });
    }

    /** @param array{receive_enabled: bool, publish_enabled: bool} $data */
    public function updateDirections(User $admin, Relay $relay, array $data): Relay
    {
        if (! $admin->canAdminister()) {
            throw new InvalidArgumentException(__('openbook.admin.relays.admin_required'));
        }

        $relay->forceFill([
            'receive_enabled' => $data['receive_enabled'],
            'publish_enabled' => $data['publish_enabled'],
        ])->save();

        $this->auditLogger->log($admin, 'relay.update', $relay, [
            'host' => parse_url($relay->inbox_url, PHP_URL_HOST),
            'receive_enabled' => $relay->receive_enabled,
            'publish_enabled' => $relay->publish_enabled,
        ]);

        return $relay->refresh();
    }
}
