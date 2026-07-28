<?php

namespace App\Domain\Posts;

use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Menzione di un Actor all'interno di un contenuto (post o, dal prossimo
 * passaggio, commento). Polimorfica fin da subito per evitare una migration
 * aggiuntiva quando verranno introdotti i commenti.
 *
 * @property string $id
 * @property string $mentionable_type
 * @property string $mentionable_id
 * @property string $actor_id
 */
class Mention extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'mentionable_type',
        'mentionable_id',
        'actor_id',
    ];

    public function mentionable(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }
}
