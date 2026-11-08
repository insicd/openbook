<?php

namespace App\Application\Services;

use App\Application\Queries\StatsPostFilter;
use App\Domain\Events\Event;
use App\Domain\Posts\Post;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

final class HashtagStats
{
    private ?Connection $writer = null;

    public function __construct(private readonly StatsPostFilter $filter, private readonly InstanceSettings $settings) {}

    /** A transient contribution, never a persisted receipt. @return array<string, array<string, int>> */
    public function snapshot(Post|Event $content): array
    {
        try {
            if (($content instanceof Post && $content->status !== null && ! $content->isPublished())
                || ($content->visibility !== null && $content->visibility !== Post::VISIBILITY_PUBLIC) || ($content instanceof Post && $content->conversation_id !== null)) {
                return [];
            }
            $published = CarbonImmutable::instance($content->published_at)->utc();
            $now = CarbonImmutable::now('UTC');
            if ($published->gt($now) || $published->lt($this->mutableSince($now))) {
                return [];
            }
            $isEvent = $content instanceof Event;
            $tags = DB::table($isEvent ? 'event_hashtags' : 'post_hashtags')
                ->where($isEvent ? 'event_id' : 'post_id', $content->id)->pluck('hashtag_id');
            if ($tags->isEmpty()) {
                return [];
            }
            $alias = $isEvent ? 'e' : 'p';
            $query = DB::table($isEvent ? 'events as e' : 'posts as p')->join('actors as a', 'a.id', '=', $alias.'.actor_id');
            $eligible = ($isEvent ? $this->filter->events($query) : $this->filter->posts($query))
                ->where($alias.'.id', $content->id)->exists();
            if (! $eligible) {
                return [];
            }
            $contribution = [];
            foreach ($tags as $tag) {
                if ($published->toDateString() >= $now->subDays(29)->toDateString()) {
                    $contribution['daily'][$tag.'|'.$published->toDateString()] = 1;
                }
                $contribution['monthly'][$tag.'|'.$published->startOfMonth()->toDateString()] = 1;
            }

            return $contribution;
        } catch (Throwable) {
            return [];
        }
    }

    public function changed(array $before, Post|Event|null $content): void
    {
        try {
            $after = $content !== null ? $this->snapshot($content) : [];
            $deltas = [];
            foreach (['daily', 'monthly'] as $period) {
                foreach (array_unique([...array_keys($before[$period] ?? []), ...array_keys($after[$period] ?? [])]) as $key) {
                    $delta = ($after[$period][$key] ?? 0) - ($before[$period][$key] ?? 0);
                    if ($delta !== 0) {
                        $deltas[$period][$key] = $delta;
                    }
                }
            }
            if ($deltas === []) {
                return;
            }
            DB::connection()->afterCommit(function () use ($deltas): void {
                try {
                    $writer = $this->connection();
                    $writer->transaction(function () use ($writer, $deltas): void {
                        foreach ($deltas as $period => $changes) {
                            ksort($changes);
                            $column = $period === 'daily' ? 'day' : 'month';
                            $table = 'stats_hashtag_'.$period.'_buckets';
                            foreach ($changes as $key => $delta) {
                                [$tag, $date] = explode('|', $key);
                                $bucket = $writer->table($table)->where('hashtag_id', $tag)->where($column, $date);
                                if ($delta > 0) {
                                    $writer->table($table)->insertOrIgnore(['hashtag_id' => $tag, $column => $date, 'count' => 0]);
                                    $bucket->increment('count', $delta);
                                } else {
                                    $bucket->where('count', '>', 0)->decrement('count');
                                }
                            }
                        }
                    });
                } catch (Throwable) {
                    // Approximate counters never fail the content operation or retry it.
                }
            });
        } catch (Throwable) {
            // Capturing or scheduling statistics is also best effort.
        }
    }

    private function mutableSince(CarbonImmutable $now): CarbonImmutable
    {
        $days = array_filter([$this->settings->remotePostPertinentRetentionDays(), $this->settings->remotePostNonPertinentRetentionDays()], fn (int $days) => $days > 0);
        $start = $now->startOfMonth()->subMonthsNoOverflow(23);

        return $days === [] ? $start : $start->max($now->subDays(min($days)));
    }

    public function deletingActor(string $actorId): void
    {
        try {
            $from = $this->mutableSince(CarbonImmutable::now('UTC'));
            foreach (Post::query()->where('actor_id', $actorId)->where('status', Post::STATUS_PUBLISHED)
                ->where('published_at', '>=', $from->setTimezone(config('app.timezone')))->lazyById(200) as $post) {
                $this->changed($this->snapshot($post), null);
            }
        } catch (Throwable) {
            // Account deletion must remain independent from statistical availability.
        }
    }

    public function expired(string $period): Builder
    {
        if (! in_array($period, ['daily', 'monthly'], true)) {
            throw new \InvalidArgumentException('Unknown hashtag bucket period.');
        }
        $now = CarbonImmutable::now('UTC');
        $column = $period === 'daily' ? 'day' : 'month';
        $cutoff = $period === 'daily' ? $now->subDays(29) : $now->startOfMonth()->subMonthsNoOverflow(23);

        return DB::table('stats_hashtag_'.$period.'_buckets')->where($column, '<', $cutoff->toDateString());
    }

    public function prune(?string $period = null): int
    {
        $count = 0;
        $deadline = hrtime(true) + 10_000_000_000;
        foreach ($period !== null ? [$period] : ['daily', 'monthly'] as $current) {
            do {
                $deleted = $this->expired($current)->orderBy($current === 'daily' ? 'day' : 'month')->limit(1000)->delete();
                $count += $deleted;
            } while ($deleted === 1000 && hrtime(true) < $deadline);
        }
        $month = CarbonImmutable::now('UTC')->startOfMonth()->subMonthsNoOverflow(23)->format('Y-m');
        DB::table('stats_collections')->where('metric', 'like', 'hashtag_seed:%')->where('metric', '<', 'hashtag_seed:'.$month)->delete();

        return $count;
    }

    public function connection(): Connection
    {
        if ($this->writer !== null) {
            return $this->writer;
        }
        $config = DB::connection()->getConfig();
        unset($config['name'], $config['read']);
        $config['timezone'] = '+00:00';
        $config['options'][PDO::ATTR_PERSISTENT] = false;
        if (isset($config['write'])) {
            $config['write']['timezone'] = '+00:00';
            $config['write']['options'] = array_replace($config['options'], $config['write']['options'] ?? [], [PDO::ATTR_PERSISTENT => false]);
        }

        $writer = DB::build($config);
        if ($writer->getDriverName() === 'sqlite') {
            $writer->statement('PRAGMA busy_timeout = 100');
        } else {
            $writer->statement('SET SESSION innodb_lock_wait_timeout = 1');
        }

        return $this->writer = $writer;
    }
}
