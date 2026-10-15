<?php

namespace App\Domain\Polls;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One resumable snapshot per poll; delivery jobs remain in the ordinary queue. */
class PollDistribution extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $attributes = ['phase' => 'actors'];

    protected $fillable = ['poll_id', 'revision', 'activity', 'phase', 'cursor'];

    protected function casts(): array
    {
        return ['activity' => 'array', 'revision' => 'integer'];
    }
}
