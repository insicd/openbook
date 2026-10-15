<?php

namespace App\Domain\Polls;

use App\Domain\Posts\Post;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RemotePoll extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['post_id', 'multiple', 'ends_at', 'closed', 'closed_at', 'voters_count'];

    protected $hidden = ['votes'];

    protected function casts(): array
    {
        return ['multiple' => 'boolean', 'closed' => 'boolean', 'ends_at' => 'datetime', 'closed_at' => 'datetime', 'voters_count' => 'integer', 'remote_updated_at' => 'datetime', 'results_received_at' => 'datetime'];
    }

    public function optionsVersion(): string
    {
        return hash('sha256', json_encode([
            $this->id, $this->multiple,
            $this->options->map(fn (RemotePollOption $option): array => [$option->id, $option->position, $option->name])->all(),
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
        return $this->hasMany(RemotePollOption::class, 'poll_id')->orderBy('position');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(RemotePollVote::class, 'poll_id');
    }
}
