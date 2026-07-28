<?php

namespace App\Federation\Actors;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Endpoint ActivityPub dichiarati da un Actor (inbox, outbox, followers,
 * following, shared inbox).
 *
 * @property string $id
 * @property string $actor_id
 * @property string|null $inbox
 * @property string|null $outbox
 * @property string|null $followers
 * @property string|null $following
 * @property string|null $shared_inbox
 */
class ActorEndpoint extends Model
{
    use HasUuids;

    protected $table = 'actor_endpoints';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'actor_id',
        'inbox',
        'outbox',
        'followers',
        'following',
        'shared_inbox',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }
}
