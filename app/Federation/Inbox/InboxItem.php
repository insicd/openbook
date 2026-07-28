<?php

namespace App\Federation\Inbox;

use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Attivita' grezza ricevuta da un inbox (per-utente o condiviso), gia'
 * autenticata e validata nella forma minima ma non ancora elaborata: la
 * trasformazione in effetti di dominio (nuovi follow, like, condivisioni...)
 * arriva con il worker "openbook:process-inbox" della Fase 4.
 *
 * @property string $id
 * @property string|null $target_actor_id
 * @property bool $is_shared
 * @property string $remote_activity_uri
 * @property string $activity_type
 * @property string $actor_uri
 * @property string $payload
 * @property string|null $signature_key_id
 * @property bool $signature_valid
 * @property string $status
 * @property string|null $error
 */
class InboxItem extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_IGNORED = 'ignored';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'target_actor_id',
        'is_shared',
        'remote_activity_uri',
        'activity_type',
        'actor_uri',
        'payload',
        'signature_key_id',
        'signature_valid',
        'status',
        'error',
        'received_at',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_shared' => 'boolean',
            'signature_valid' => 'boolean',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function targetActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'target_actor_id');
    }
}
