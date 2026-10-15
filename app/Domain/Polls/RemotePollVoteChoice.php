<?php

namespace App\Domain\Polls;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemotePollVoteChoice extends Model
{
    use HasUuids;

    protected $fillable = ['vote_id', 'position', 'name'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $option): void {
            $option->name_hash = hash('sha256', $option->name);
        });
    }

    public function vote(): BelongsTo
    {
        return $this->belongsTo(RemotePollVote::class, 'vote_id');
    }
}
