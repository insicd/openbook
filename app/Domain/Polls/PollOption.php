<?php

namespace App\Domain\Polls;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class PollOption extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['poll_id', 'position', 'name', 'votes_count'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'votes_count' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $option): void {
            if ($option->exists && ($option->isDirty('poll_id')
                || ($option->isDirty(['position', 'name']) && $option->poll->isLocal()))) {
                throw ValidationException::withMessages(['poll' => __('openbook.polls.immutable')]);
            }
            $option->name_hash = hash('sha256', $option->name);
        });
    }

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class, 'poll_id');
    }

    public function choices(): HasMany
    {
        return $this->hasMany(PollVoteChoice::class, 'option_id');
    }
}
