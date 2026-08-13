<?php

namespace App\Domain\Feeds;

use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Sorgente RSS/Atom collegata a un Actor di tipo "feed".
 *
 * @property string $id
 * @property string $actor_id
 * @property string $feed_url
 * @property string $feed_url_hash
 * @property string|null $site_url
 * @property string $format
 * @property string|null $etag
 * @property string|null $last_modified
 * @property Carbon|null $last_fetched_at
 * @property Carbon|null $last_success_at
 * @property string|null $last_error
 */
class FeedSource extends Model
{
    use HasUuids;

    public const FORMAT_ATOM = 'atom';

    public const FORMAT_RSS = 'rss';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'actor_id',
        'feed_url',
        'feed_url_hash',
        'site_url',
        'format',
        'etag',
        'last_modified',
        'last_fetched_at',
        'last_success_at',
        'last_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_fetched_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    public static function hashUrl(string $feedUrl): string
    {
        return hash('sha256', $feedUrl);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }
}
