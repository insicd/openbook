<?php

namespace App\Domain\Polls;

use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Poll extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['post_id', 'multiple', 'ends_at', 'closed', 'closed_at', 'voters_count',
        'results_updated_at', 'revision', 'publication_pending', 'needs_delivery'];

    protected $attributes = ['closed' => false, 'revision' => 0, 'publication_pending' => false,
        'needs_delivery' => false, 'distributed_revision' => 0];

    protected $hidden = ['votes', 'viewerVote', 'viewerCanSee', 'revision', 'publication_pending', 'needs_delivery', 'distributed_revision'];

    protected function casts(): array
    {
        return ['multiple' => 'boolean', 'closed' => 'boolean', 'ends_at' => 'datetime', 'closed_at' => 'datetime',
            'voters_count' => 'integer', 'remote_updated_at' => 'datetime', 'results_received_at' => 'datetime',
            'results_updated_at' => 'datetime', 'revision' => 'integer', 'publication_pending' => 'boolean',
            'needs_delivery' => 'boolean', 'distributed_revision' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $poll): void {
            if ($poll->isDirty('post_id') || ($poll->isDirty(['multiple', 'ends_at']) && $poll->isLocal())) {
                throw ValidationException::withMessages(['poll' => __('openbook.polls.immutable')]);
            }
        });
    }

    public function isLocal(): bool
    {
        return ! $this->post->isRemote();
    }

    /** Personal choices are request-local and always scoped to the current Actor. */
    public static function annotateViewerState(iterable $polls, ?Actor $viewer): void
    {
        $polls = collect($polls)->filter();
        if ($polls->isEmpty()) {
            return;
        }
        $visible = Post::query()->whereKey($polls->pluck('post_id'))->where('status', Post::STATUS_PUBLISHED)
            ->visibleTo($viewer)->pluck('id')->all();
        foreach ($polls as $poll) {
            $poll->setRelation('viewerVote', null);
            $poll->setRelation('viewerCanSee', in_array($poll->post_id, $visible, true));
        }
        $polls = $polls->filter(fn (self $poll): bool => $poll->getRelation('viewerCanSee'));
        if ($viewer === null || $polls->isEmpty()) {
            return;
        }
        $votes = PollVote::query()->where('actor_id', $viewer->id)
            ->whereIn('poll_id', $polls->pluck('id'))->with('choices')->get()->keyBy('poll_id');
        foreach ($polls as $poll) {
            $poll->setRelation('viewerVote', $votes->get($poll->id));
        }
    }

    /** @return array<string, int> Percentages require a known, consistent denominator. */
    public function resultPercentages(): array
    {
        $voters = $this->voters_count;
        if ($voters === null || $voters <= 0
            || $this->options->contains(fn (PollOption $option): bool => $option->votes_count !== null && $option->votes_count > $voters)
            || (! $this->multiple && ($this->options->contains(fn (PollOption $option): bool => $option->votes_count === null)
                || $this->options->sum('votes_count') !== $voters))) {
            return [];
        }

        return $this->options->filter(fn (PollOption $option): bool => $option->votes_count !== null)
            ->mapWithKeys(fn (PollOption $option): array => [$option->id => (int) round(100 * $option->votes_count / $voters)])->all();
    }

    public function optionsVersion(): string
    {
        return hash('sha256', json_encode([
            $this->id, $this->multiple,
            $this->options->map(fn (PollOption $option): array => [$option->id, $option->position, $option->name])->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public function isClosed(): bool
    {
        return $this->closed
            || ($this->closed_at !== null && $this->closed_at->lessThanOrEqualTo(now()))
            || ($this->ends_at !== null && $this->ends_at->lessThanOrEqualTo(now()));
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class, 'poll_id')->orderBy('position');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class, 'poll_id');
    }
}
