<?php

namespace App\Domain\Polls;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemotePollOption extends Model
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
            $option->name_hash = hash('sha256', $option->name);
        });
    }

    public function poll(): BelongsTo
    {
        return $this->belongsTo(RemotePoll::class, 'poll_id');
    }
}
