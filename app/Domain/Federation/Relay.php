<?php

namespace App\Domain\Federation;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Configurazione amministrativa di un relay ActivityPub.
 *
 * Descrive endpoint, protocollo, direzioni e stato della relazione federata.
 *
 * @property string $id
 * @property string $protocol
 * @property string|null $actor_uri
 * @property string $inbox_url
 * @property string $inbox_url_hash
 * @property string|null $follow_activity_uri
 * @property string $state
 * @property bool $receive_enabled
 * @property bool $publish_enabled
 * @property string|null $last_error
 * @property Carbon|null $accepted_at
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_failure_at
 */
class Relay extends Model
{
    use HasUuids;

    public const PROTOCOL_MASTODON = 'mastodon';

    public const PROTOCOL_ACTOR = 'actor';

    public const PROTOCOL_LITEPUB = 'litepub';

    public const SUPPORTED_PROTOCOLS = [
        self::PROTOCOL_MASTODON,
        self::PROTOCOL_ACTOR,
        self::PROTOCOL_LITEPUB,
    ];

    public const ACTOR_PROTOCOLS = [
        self::PROTOCOL_ACTOR,
        self::PROTOCOL_LITEPUB,
    ];

    public const STATE_IDLE = 'idle';

    public const STATE_PENDING = 'pending';

    public const STATE_ACCEPTED = 'accepted';

    public const STATE_REJECTED = 'rejected';

    public const STATE_FAILED = 'failed';

    /** @var list<string> */
    protected $fillable = [
        'protocol',
        'actor_uri',
        'inbox_url',
        'inbox_url_hash',
        'follow_activity_uri',
        'state',
        'receive_enabled',
        'publish_enabled',
        'last_error',
        'accepted_at',
        'last_success_at',
        'last_failure_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'receive_enabled' => 'boolean',
            'publish_enabled' => 'boolean',
            'accepted_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    public function acceptsIncomingActivities(): bool
    {
        return $this->state === self::STATE_ACCEPTED && $this->receive_enabled;
    }

    public function publishesOutgoingActivities(): bool
    {
        return $this->state === self::STATE_ACCEPTED && $this->publish_enabled;
    }

    public function usesActorHandshake(): bool
    {
        return in_array($this->protocol, self::ACTOR_PROTOCOLS, true);
    }

    public function requiresReciprocalFollow(): bool
    {
        return $this->protocol === self::PROTOCOL_LITEPUB;
    }
}
