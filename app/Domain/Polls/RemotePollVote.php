<?php

namespace App\Domain\Polls;

use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RemotePollVote extends Model
{
    use HasUuids;

    protected $fillable = ['poll_id', 'actor_id'];

    protected $hidden = ['actor_id', 'actor', 'choices'];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(RemotePoll::class, 'poll_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    public function choices(): HasMany
    {
        return $this->hasMany(RemotePollVoteChoice::class, 'vote_id')->orderBy('position');
    }
}
