<?php

namespace App\Domain\Polls;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class PollVoteChoice extends Model
{
    use HasUuids;

    protected $fillable = ['vote_id', 'position', 'name', 'option_id', 'remote_uri_hash', 'created_at'];

    protected $hidden = ['vote', 'remote_uri_hash'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $choice): void {
            if (! $choice->exists && $choice->option_id !== null) {
                $option = $choice->option;
                if ($option === null || $option->poll_id !== $choice->vote->poll_id) {
                    throw ValidationException::withMessages(['choices' => __('openbook.polls.choose')]);
                }
                $choice->name ??= $option->name;
                $choice->position ??= $option->position;
            }
            $choice->name_hash = hash('sha256', $choice->name);
        });
    }

    public function vote(): BelongsTo
    {
        return $this->belongsTo(PollVote::class, 'vote_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(PollOption::class, 'option_id');
    }
}
