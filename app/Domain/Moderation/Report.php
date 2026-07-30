<?php

namespace App\Domain\Moderation;

use App\Domain\Accounts\User;
use App\Domain\Posts\Post;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Segnalazione locale di un post (remoto o locale). Non e' un'attivita'
 * ActivityPub: serve solo alla moderazione dell'istanza tramite il futuro
 * pannello di controllo.
 *
 * @property string $id
 * @property string $reporter_id
 * @property string $post_id
 * @property string $reason
 * @property string|null $details
 * @property string $status
 * @property string|null $reviewed_by
 * @property Carbon|null $reviewed_at
 */
class Report extends Model
{
    use HasUuids;

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
        'reason',
        'details',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
