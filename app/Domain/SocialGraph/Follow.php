<?php

namespace App\Domain\SocialGraph;

use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Relazione di follow tra due Actor. Modellata a livello di Actor (non di
 * User) perche' sul piano ActivityPub il follow avviene sempre tra Actor:
 * questa tabella e' gia' pronta per il follow federato della Fase 4, che la
 * riusera' senza modifiche di schema (i due Actor coinvolti potranno essere
 * remoti). In questo milestone entrambi i lati sono sempre locali.
 *
 * @property string $id
 * @property string $follower_id
 * @property string $following_id
 * @property string $status
 * @property string|null $remote_activity_uri
 * @property Carbon $requested_at
 * @property Carbon|null $accepted_at
 */
class Follow extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'follower_id',
        'following_id',
        'status',
        'remote_activity_uri',
        'requested_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function follower(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'follower_id');
    }

    public function following(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'following_id');
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
