<?php

namespace App\Console\Commands;

use App\Domain\Feeds\FeedImporter;
use App\Domain\Feeds\FeedSource;
use App\Domain\SocialGraph\Follow;
use Illuminate\Console\Command;

/**
 * Aggiorna i feed RSS/Atom seguiti da almeno un Actor locale: le nuove
 * voci diventano Post pubblici dell'Actor "feed" e compaiono nei timeline
 * di chi lo segue.
 */
class FetchFeedsCommand extends Command
{
    protected $signature = 'openbook:fetch-feeds
        {--limit=8 : Numero massimo di feed da aggiornare}
        {--max-time=15 : Tempo massimo di esecuzione in secondi}';

    protected $description = 'Recupera i feed RSS/Atom seguiti e importa le nuove voci.';

    public function handle(FeedImporter $importer): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $deadline = microtime(true) + max(1, (int) $this->option('max-time'));
        $minInterval = max(5, (int) config('openbook.feeds.min_fetch_interval_minutes', 30));

        $sources = FeedSource::query()
            ->with('actor')
            ->whereHas('actor', function ($query): void {
                $query->where('type', \App\Federation\Actors\Actor::TYPE_FEED)
                    ->where('status', \App\Federation\Actors\Actor::STATUS_ACTIVE)
                    ->whereHas('followerRelations', function ($followQuery): void {
                        $followQuery->where('status', Follow::STATUS_ACCEPTED)
                            ->whereHas('follower', static fn ($follower) => $follower->where('is_local', true));
                    });
            })
            ->where(function ($query) use ($minInterval): void {
                $query->whereNull('last_fetched_at')
                    ->orWhere('last_fetched_at', '<=', now()->subMinutes($minInterval));
            })
            ->orderBy('last_fetched_at')
            ->limit($limit)
            ->get();

        $created = 0;
        $fetched = 0;

        foreach ($sources as $source) {
            if (microtime(true) >= $deadline) {
                break;
            }

            $actor = $source->actor;

            if ($actor === null) {
                continue;
            }

            $created += $importer->import($actor);
            $fetched++;
        }

        $this->info("Aggiornati {$fetched} feed, {$created} nuovi post.");

        return self::SUCCESS;
    }
}
