<?php

namespace App\Domain\Moderation;

use App\Domain\Accounts\User;
use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Caso di moderazione locale, aperto da un utente o da un Flag federato.
 *
 * @property string $id
 * @property string|null $reporter_id
 * @property string|null $post_id
 * @property string|null $comment_id
 * @property string $reason
 * @property string|null $details
 * @property string $status
 * @property string|null $reviewed_by
 * @property Carbon|null $reviewed_at
 */
class Report extends Model
{
    use HasUuids;

    public const ORIGIN_LOCAL = 'local';

    public const ORIGIN_FEDERATED = 'federated';

    public const CLOSURE_UNAVAILABLE = 'content_unavailable';

    public const REASON_SPAM = 'spam';

    public const REASON_HARASSMENT = 'harassment';

    public const REASON_HATE = 'hate';

    public const REASON_ILLEGAL = 'illegal';

    public const REASON_OTHER = 'other';

    public const STATUS_OPEN = 'open';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_ACTIONED = 'actioned';

    /**
     * @return list<string>
     */
    public static function reasons(): array
    {
        return [
            self::REASON_SPAM,
            self::REASON_HARASSMENT,
            self::REASON_HATE,
            self::REASON_ILLEGAL,
            self::REASON_OTHER,
        ];
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'reporter_id',
        'post_id',
        'comment_id',
        'reason',
        'details',
        'status',
        'reviewed_by',
        'reviewed_at',
        'origin',
        'reporter_actor_id',
        'reporter_uri',
        'reporter_domain',
        'remote_activity_uri',
        'closed_at',
        'closure_reason',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reporterActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'reporter_actor_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(ReportTarget::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isFederated(): bool
    {
        return $this->origin === self::ORIGIN_FEDERATED;
    }

    /** @return list<string> */
    public function targetTypes(): array
    {
        return $this->isFederated()
            ? $this->targets->pluck('type')->unique()->values()->all()
            : [$this->isCommentReport() ? ReportTarget::TYPE_COMMENT : ReportTarget::TYPE_POST];
    }

    public function isCommentReport(): bool
    {
        return $this->comment_id !== null;
    }
}
