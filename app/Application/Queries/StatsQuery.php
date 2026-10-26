<?php

namespace App\Application\Queries;

use App\Application\Services\DomainBlockManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PDO;

final class StatsQuery
{
    private ?Connection $statsConnection = null;

    public function __construct(private readonly DomainBlockManager $domainBlocks) {}

    public function rhythm(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $bucket = $this->connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%dT%H:00:00Z', openbook_stats_utc(p.published_at))"
            : "DATE_FORMAT(p.published_at, '%Y-%m-%dT%H:00:00Z')";

        return $this->posts($from, $to)
            ->selectRaw("{$bucket} AS bucket, COUNT(*) AS count")
            ->groupBy('bucket')->orderBy('bucket')->get();
    }

    public function languages(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $posts = $this->languageUses($this->posts($from, $to), 'p');
        $events = $this->languageUses($this->events($from, $to), 'e');

        return $this->connection()->query()->fromSub($posts->unionAll($events), 'uses')
            ->selectRaw('language, SUM(count) AS count')->groupBy('language')->orderByDesc('count')->orderBy('language')->get();
    }

    public function domains(): Collection
    {
        return $this->authors($this->connection()->table('actors as a'))
            ->selectRaw('a.domain, COUNT(*) AS count, SUM(CASE WHEN a.is_local = 1 THEN 1 ELSE 0 END) AS local_count')
            ->groupBy('a.domain')->orderByDesc('count')->orderBy('a.domain')->get();
    }

    public function weekly(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $week = $this->week('p');
        $query = $this->posts($from, $to);
        $connection = $query->getConnection();
        // Global history is faster through the date range than per-actor lookups.
        if ($connection instanceof MySqlConnection && ! $connection->isMaria()) {
            $query->forceIndex('posts_visibility_status_published_at_index');
        }

        return $query
            ->selectRaw("{$week} AS week, COUNT(*) AS count, COUNT(DISTINCT p.actor_id) AS authors")
            ->groupBy('week')->orderBy('week')->get();
    }

    public function hashtags(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $connection = $this->connection();
        $month = $connection->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', openbook_stats_utc(p.published_at))"
            : "DATE_FORMAT(p.published_at, '%Y-%m')";
        $counts = $this->posts($from, $to)->join('post_hashtags as ph', 'ph.post_id', '=', 'p.id')
            ->selectRaw("{$month} AS month, ph.hashtag_id, COUNT(*) AS count")
            ->groupBy('month', 'ph.hashtag_id');
        $totals = $connection->query()->fromSub($counts, 'mc')
            ->selectRaw('mc.*, SUM(count) OVER (PARTITION BY hashtag_id) AS total');
        $ranked = $connection->query()->fromSub($totals, 'tc')->join('hashtags as h', 'h.id', '=', 'tc.hashtag_id')
            ->where('h.name', '<>', '')
            ->selectRaw('tc.*, h.name,
                ROW_NUMBER() OVER (PARTITION BY month ORDER BY count DESC, h.name, h.id) AS month_rank,
                DENSE_RANK() OVER (ORDER BY total DESC, h.name, h.id) AS overall_rank');

        // One statement gives monthly winners and the period legend from the same snapshot.
        return $connection->query()->fromSub($ranked, 'ranked')
            ->where(fn (Builder $query) => $query->where('month_rank', '<=', 10)->orWhere('overall_rank', '<=', 10))
            ->select('hashtag_id as id', 'name', 'month', 'count', 'month_rank', 'overall_rank')
            ->orderBy('overall_rank')->orderBy('month')->get();
    }

    private function week(string $alias): string
    {
        return $this->connection()->getDriverName() === 'sqlite'
            ? "date(openbook_stats_utc({$alias}.published_at), 'weekday 0', '-6 days')"
            : "DATE_SUB(DATE({$alias}.published_at), INTERVAL WEEKDAY({$alias}.published_at) DAY)";
    }

    public function geography(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $country = "COALESCE(NULLIF(UPPER(TRIM(l.country_code)), ''), 'UNKNOWN')";
        $posts = $this->posts($from, $to)->join('post_locations as l', 'l.post_id', '=', 'p.id')
            ->selectRaw("{$country} AS country, COUNT(*) AS count")->groupByRaw($country);
        $events = $this->events($from, $to)->join('event_locations as l', 'l.event_id', '=', 'e.id')
            ->selectRaw("{$country} AS country, COUNT(*) AS count")->groupByRaw($country);

        return $this->connection()->query()->fromSub($posts->unionAll($events), 'geo')
            ->selectRaw('country, SUM(count) AS count')->groupBy('country')->get();
    }

    public function eventCalendar(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $month = $this->connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', openbook_stats_utc(e.start_at))"
            : "DATE_FORMAT(e.start_at, '%Y-%m')";

        return $this->events($from, $to)->selectRaw("{$month} AS month, COUNT(*) AS count")
            ->groupBy('month')->orderBy('month')->get();
    }

    public function communityActivity(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $week = $this->week('p');
        $actors = $this->authors($this->connection()->table('actors as a'))
            ->where('a.type', 'group')
            ->whereNotExists(function (Builder $community): void {
                $community->selectRaw('1')->from('communities as c')
                    ->whereColumn('c.actor_id', 'a.id')->where('c.is_private', true);
            })->select('a.id');

        $local = $this->posts($from, $to)
            ->join('communities as community', 'community.id', '=', 'p.community_id')
            ->joinSub(clone $actors, 'community_author', 'community_author.id', '=', 'community.actor_id')
            ->where('community.is_private', false)
            ->selectRaw("{$week} AS week, p.id AS post_id, community_author.id AS community_actor_id");
        $published = $this->posts($from, $to)
            ->joinSub(clone $actors, 'community_author', 'community_author.id', '=', 'p.actor_id')
            ->selectRaw("{$week} AS week, p.id AS post_id, community_author.id AS community_actor_id");
        $shared = $this->posts($from, $to)
            ->join('announces as an', 'an.post_id', '=', 'p.id')->where('an.is_direct', true)
            ->joinSub($actors, 'community_author', 'community_author.id', '=', 'an.actor_id')
            ->selectRaw("{$week} AS week, p.id AS post_id, community_author.id AS community_actor_id");

        // A local post may also have a Group Announce; count each post and Group once per week.
        return $this->connection()->query()->fromSub($local->unionAll($published)->unionAll($shared), 'activity')
            ->selectRaw('week, COUNT(DISTINCT community_actor_id) AS active, COUNT(DISTINCT post_id) AS count')
            ->groupBy('week')->orderBy('week')->get();
    }

    public function media(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $week = $this->week('p');
        $image = "LOWER(m.mime_type) LIKE 'image/%'";
        $video = "LOWER(m.mime_type) LIKE 'video/%'";
        $connection = $this->connection();
        // Avoid filtering the entire post corpus before looking up attachments.
        $hint = $connection instanceof MySqlConnection && ! $connection->isMaria()
            ? '/*+ JOIN_PREFIX(att) */ '
            : '';

        return $this->posts($from, $to)
            ->join('post_attachments as att', 'att.post_id', '=', 'p.id')
            ->join('media as m', 'm.id', '=', 'att.media_id')
            ->where(fn (Builder $query) => $query->whereRaw($image)->orWhereRaw($video))
            ->selectRaw("{$hint}{$week} AS week,
                SUM(CASE WHEN {$image} THEN 1 ELSE 0 END) AS images,
                SUM(CASE WHEN {$video} THEN 1 ELSE 0 END) AS videos,
                COUNT(DISTINCT CASE WHEN {$image} THEN p.id END) AS image_posts,
                COUNT(DISTINCT CASE WHEN {$video} THEN p.id END) AS video_posts,
                COUNT(DISTINCT p.id) AS posts")
            ->groupBy('week')->orderBy('week')->get();
    }

    public function polls(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $week = $this->week('p');

        return $this->posts($from, $to)->join('polls as pol', 'pol.post_id', '=', 'p.id')
            ->selectRaw("{$week} AS week, COUNT(*) AS polls")
            ->groupBy('week')->orderBy('week')->get();
    }

    public function acquisitionCoverage(): ?string
    {
        return $this->connection()->table('stats_collections')->where('metric', 'content_delivery')->value('started_at');
    }

    public function acquisition(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $week = $this->connection()->getDriverName() === 'sqlite'
            ? "date(b.day, 'weekday 0', '-6 days')"
            : 'DATE_SUB(b.day, INTERVAL WEEKDAY(b.day) DAY)';

        return $this->connection()->table('stats_content_delivery_buckets as b')
            ->join('stats_delivery_sources as s', 's.id', '=', 'b.source_id')
            ->where('b.day', '>=', $from->format('Y-m-d'))->where('b.day', '<=', $to->format('Y-m-d'))
            ->selectRaw("{$week} AS week, s.id, s.kind, s.label, SUM(b.count) AS count")
            ->groupBy('week', 's.id', 's.kind', 's.label')->orderBy('s.kind')->orderBy('s.id')->orderBy('week')->get();
    }

    private function languageUses(Builder $query, string $alias): Builder
    {
        $language = "LOWER(TRIM({$alias}.language))";

        return $query->whereNotNull($alias.'.language')->whereRaw("{$language} NOT IN ('', 'und')")
            ->selectRaw("{$language} AS language, COUNT(*) AS count")->groupByRaw($language);
    }

    private function posts(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        [$from, $to] = $this->bounds($from, $to);
        $query = $this->authors($this->connection()->table('posts as p')->join('actors as a', 'a.id', '=', 'p.actor_id'))
            ->where('p.status', 'published')->where('p.visibility', 'public')
            ->whereNull('p.conversation_id')
            ->where('p.published_at', '>=', $from)->where('p.published_at', '<', $to)
            ->where(function (Builder $query): void {
                $query->whereNull('p.community_id')->orWhereExists(function (Builder $community): void {
                    $community->selectRaw('1')->from('communities as c')
                        ->whereColumn('c.id', 'p.community_id')->where('c.is_private', false);
                });
            });

        if (config('openbook.moderation.hide_content_warnings_from_world', false)) {
            $query->where(fn (Builder $cw) => $cw->whereNull('p.content_warning')->orWhere('p.content_warning', ''));
            $this->excludeForcedTags($query, 'post_hashtags', 'post_id', 'p.id');
        }

        return $query;
    }

    private function events(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        [$from, $to] = $this->bounds($from, $to);
        $query = $this->authors($this->connection()->table('events as e')->join('actors as a', 'a.id', '=', 'e.actor_id'))
            ->where('e.visibility', 'public')->whereNull('e.deleted_at')
            ->whereIn('e.status', ['scheduled', 'tentative', 'postponed'])
            ->where('e.published_at', '>=', $from)->where('e.published_at', '<', $to);

        if (config('openbook.moderation.hide_content_warnings_from_world', false)) {
            $query->where('e.sensitive', false);
            $this->excludeForcedTags($query, 'event_hashtags', 'event_id', 'e.id');
        }

        return $query;
    }

    private function connection(): Connection
    {
        if ($this->statsConnection !== null) {
            return $this->statsConnection;
        }

        $connection = DB::connection();
        if ($connection->getDriverName() === 'sqlite') {
            // SQLite stores the application's wall clock, without TIMESTAMP conversion.
            $timezone = (string) config('app.timezone', 'UTC');
            $connection->getPdo()->sqliteCreateFunction('openbook_stats_utc', static function (?string $value) use ($timezone): ?string {
                return $value === null ? null : CarbonImmutable::parse($value, $timezone)->utc()->format('Y-m-d H:i:s');
            }, 1);

            return $this->statsConnection = $connection;
        }

        // Read MySQL TIMESTAMPs in UTC without changing other application queries.
        $config = $connection->getConfig();
        unset($config['name']);
        $config['timezone'] = '+00:00';
        $config['options'][PDO::ATTR_PERSISTENT] = false;
        foreach (['read', 'write'] as $role) {
            if (isset($config[$role])) {
                $config[$role]['timezone'] = '+00:00';
                $config[$role]['options'] = array_replace($config['options'], $config[$role]['options'] ?? [], [PDO::ATTR_PERSISTENT => false]);
            }
        }

        return $this->statsConnection = DB::build($config);
    }

    private function bounds(CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($this->connection()->getDriverName() === 'sqlite') {
            $timezone = (string) config('app.timezone', 'UTC');

            return [$from->setTimezone($timezone), $to->setTimezone($timezone)];
        }

        return [$from, $to];
    }

    private function authors(Builder $query): Builder
    {
        $query->where('a.status', 'active')->where('a.remote_suspended', false)
            ->whereNull('a.deleted_at');

        $blocked = $this->domainBlocks->blockedHosts();
        $query->whereNotIn('a.domain', $blocked);
        foreach ($blocked as $host) {
            // Handles and canonical Actor URIs can use different hosts.
            foreach (['https://', 'http://'] as $scheme) {
                $origin = $scheme.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $host);
                $query->where('a.uri', '<>', $scheme.$host);
                foreach (['/', ':', '?', '#'] as $boundary) {
                    $query->where('a.uri', 'not like', $origin.$boundary.'%');
                }
            }
        }

        return $query;
    }

    private function excludeForcedTags(Builder $query, string $pivot, string $foreignKey, string $contentId): void
    {
        $tags = config('openbook.moderation.forced_content_warning_hashtags', []);
        if (! is_array($tags) || $tags === []) {
            return;
        }

        $query->whereNotExists(function (Builder $tag) use ($pivot, $foreignKey, $contentId, $tags): void {
            $tag->selectRaw('1')->from($pivot.' as ph')
                ->join('hashtags as h', 'h.id', '=', 'ph.hashtag_id')
                ->whereColumn('ph.'.$foreignKey, $contentId)->whereIn('h.name', $tags);
        });
    }
}
