<?php

namespace App\Federation\Actors;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Coppia di chiavi RSA utilizzata per le firme HTTP delle attivita' inviate
 * o per verificare quelle ricevute. La chiave privata e' presente soltanto
 * per gli Actor locali, e' sempre cifrata a riposo tramite il cast Eloquent
 * "encrypted:array"-like ("encrypted") e non deve mai comparire in log,
 * risposte API o messaggi di errore.
 *
 * @property string $id
 * @property string $actor_id
 * @property string $public_key
 * @property string|null $private_key
 */
class ActorKey extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'actor_id',
        'public_key',
        'private_key',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'private_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    public function hasPrivateKey(): bool
    {
        return ! empty($this->private_key);
    }
}
