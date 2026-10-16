<?php

namespace App\Domain\Polls;

use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PollVote extends Model
{
    use HasUuids;

    protected $fillable = ['poll_id', 'actor_id', 'voter_hash', 'created_at'];

    protected $hidden = ['actor_id', 'actor', 'choices', 'voter_hash'];

    protected static function booted(): void
    {
        static::creating(function (self $vote): void {
            if ($vote->actor_id !== null && $vote->voter_hash === null) {
                $vote->voter_hash = self::identityHash(Actor::query()->findOrFail($vote->actor_id)->uri);
            }
        });
    }

    public static function identityHash(string $uri): string
    {
        return hash_hmac('sha256', $uri, (string) config('app.key'));
    }

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class, 'poll_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    public function choices(): HasMany
    {
        return $this->hasMany(PollVoteChoice::class, 'vote_id')->orderBy('position');
    }
}
