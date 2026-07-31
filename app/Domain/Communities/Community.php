<?php

namespace App\Domain\Communities;

use App\Domain\Accounts\User;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Community locale (forum / gruppo), collegata a un Actor ActivityPub di tipo
 * Group. L'iscrizione riusa i Follow verso l'Actor; i post destinati alla
 * community hanno {@see Post::$community_id}.
 *
 * @property string $id
 * @property string $actor_id
 * @property string $owner_user_id
 * @property string $slug
 * @property bool $is_private
 * @property int $members_count
 * @property int $posts_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Community extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'actor_id',
        'owner_user_id',
        'slug',
        'is_private',
        'members_count',
        'posts_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'members_count' => 'integer',
            'posts_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_user_id === $user->id;
    }

    public function isMember(Actor $actor): bool
    {
        return Follow::query()
            ->where('follower_id', $actor->id)
            ->where('following_id', $this->actor_id)
            ->where('status', Follow::STATUS_ACCEPTED)
            ->exists();
    }

    public function url(): string
    {
        return route('communities.show', $this);
    }
}
