<?php

namespace App\Domain\Moderation;

use App\Domain\Comments\Comment;
use App\Domain\Events\Event;
use App\Domain\Events\EventComment;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportTarget extends Model
{
    use HasUuids;

    public const TYPE_ACTOR = 'actor';

    public const TYPE_POST = 'post';

    public const TYPE_COMMENT = 'comment';

    public const TYPE_EVENT = 'event';

    public const TYPE_EVENT_COMMENT = 'event_comment';

    public $timestamps = false;

    protected $fillable = [
        'report_id', 'type', 'uri', 'uri_hash', 'unavailable',
        'actor_id', 'post_id', 'comment_id', 'event_id', 'event_comment_id',
    ];

    protected function casts(): array
    {
        return ['unavailable' => 'boolean'];
    }

    public static function isLocalPersonSubject(Actor|Post|Comment|Event|EventComment $subject): bool
    {
        $actor = $subject instanceof Actor ? $subject : $subject->actor;

        return $actor !== null && $actor->isLocal() && $actor->isPerson()
            && ($subject instanceof Actor || ! $subject->isRemote());
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function eventComment(): BelongsTo
    {
        return $this->belongsTo(EventComment::class);
    }
}
