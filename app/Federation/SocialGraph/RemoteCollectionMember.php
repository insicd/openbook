<?php

namespace App\Federation\SocialGraph;

use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Voce di una collection remota followers/following, in cache locale.
 * Non e' un Follow: non genera feed, notifiche ne' consegna federata.
 *
 * @property string $id
 * @property string $actor_id
 * @property string $collection
 * @property string $member_uri
 * @property string|null $member_actor_id
 * @property int $position
 */
class RemoteCollectionMember extends Model
{
    use HasUuids;

    public const COLLECTION_FOLLOWERS = 'followers';

    public const COLLECTION_FOLLOWING = 'following';

    protected $table = 'remote_collection_members';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'actor_id',
        'collection',
        'member_uri',
        'member_actor_id',
        'position',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'actor_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'member_actor_id');
    }
}
