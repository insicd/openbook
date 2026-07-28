<?php

namespace App\Domain\Reactions;

use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * "Mi piace" locale. La rappresentazione federata (attivita' ActivityPub
 * {@code Like}) verra' aggiunta nel passaggio dedicato alla federazione
 * sociale (Fase 4): questo modello e' gia' polimorfico ("likeable") cosi' da
 * coprire sia i post sia i commenti senza modifiche di schema.
 *
 * @property string $id
 * @property string $actor_id
 * @property string $likeable_type
 * @property string $likeable_id
 */
class Like extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'actor_id',
        'likeable_type',
        'likeable_id',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    public function likeable(): MorphTo
    {
        return $this->morphTo();
    }
}
