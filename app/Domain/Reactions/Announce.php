<?php

namespace App\Domain\Reactions;

use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Condivisione locale di un post (attivita' ActivityPub {@code Announce} in
 * Fase 4). Non duplica mai il contenuto originale: e' solo un riferimento
 * "attore ha condiviso questo post".
 *
 * @property string $id
 * @property string $actor_id
 * @property string $post_id
 */
class Announce extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'actor_id',
        'post_id',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
