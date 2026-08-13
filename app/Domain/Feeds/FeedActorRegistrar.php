<?php

namespace App\Domain\Feeds;

use App\Federation\Actors\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crea o aggiorna un Actor di tipo "feed" a partire da un URL scoperto.
 */
final class FeedActorRegistrar
{
    public function __construct(
        private readonly FeedDiscoverer $discoverer,
    ) {}

    public function resolveFromUrl(string $url): Actor
    {
        $discovered = $this->discoverer->discover($url);

        return $this->upsertFromDiscovered($discovered);
    }

    public function upsertFromDiscovered(DiscoveredFeed $discovered): Actor
    {
        return DB::transaction(function () use ($discovered) {
            $existingSource = FeedSource::query()
                ->where('feed_url_hash', FeedSource::hashUrl($discovered->feedUrl))
                ->first();

            if ($existingSource !== null) {
                $actor = $existingSource->actor()->firstOrFail();
                $this->applyDiscovered($actor, $existingSource, $discovered);

                return $actor->fresh(['feedSource']) ?? $actor;
            }

            $byUri = Actor::query()->where('uri', $discovered->feedUrl)->first();

            if ($byUri !== null && $byUri->isFeed()) {
                $source = $byUri->feedSource ?? new FeedSource(['actor_id' => $byUri->id]);
                $this->applyDiscovered($byUri, $source, $discovered);

                return $byUri->fresh(['feedSource']) ?? $byUri;
            }

            $host = $this->hostFromUrl($discovered->siteUrl ?: $discovered->feedUrl);
            $username = $this->uniqueUsername($host, $discovered->feedUrl);

            $actor = Actor::query()->create([
                'user_id' => null,
                'type' => Actor::TYPE_FEED,
                'is_local' => false,
                'preferred_username' => $username,
                'domain' => $host,
                'uri' => $discovered->feedUrl,
                'name' => mb_substr($discovered->title, 0, 255),
                'summary' => $discovered->summary !== null
                    ? mb_substr(strip_tags($discovered->summary), 0, 5000)
                    : null,
                'icon_url' => $discovered->iconUrl,
                'image_url' => null,
                'manually_approves_followers' => false,
                'status' => Actor::STATUS_ACTIVE,
                'last_fetched_at' => now(),
            ]);

            FeedSource::query()->create([
                'actor_id' => $actor->id,
                'feed_url' => $discovered->feedUrl,
                'feed_url_hash' => FeedSource::hashUrl($discovered->feedUrl),
                'site_url' => $discovered->siteUrl,
                'format' => $discovered->format,
                'etag' => $discovered->etag,
                'last_modified' => $discovered->lastModified,
                'last_fetched_at' => now(),
                'last_success_at' => now(),
                'last_error' => null,
            ]);

            return $actor->fresh(['feedSource']) ?? $actor;
        });
    }

    private function applyDiscovered(Actor $actor, FeedSource $source, DiscoveredFeed $discovered): void
    {
        $actor->fill([
            'name' => mb_substr($discovered->title, 0, 255),
            'summary' => $discovered->summary !== null
                ? mb_substr(strip_tags($discovered->summary), 0, 5000)
                : $actor->summary,
            'icon_url' => $discovered->iconUrl ?: $actor->icon_url,
            'uri' => $discovered->feedUrl,
            'last_fetched_at' => now(),
            'status' => Actor::STATUS_ACTIVE,
        ])->save();

        $source->fill([
            'actor_id' => $actor->id,
            'feed_url' => $discovered->feedUrl,
            'feed_url_hash' => FeedSource::hashUrl($discovered->feedUrl),
            'site_url' => $discovered->siteUrl ?: $source->site_url,
            'format' => $discovered->format,
            'etag' => $discovered->etag ?: $source->etag,
            'last_modified' => $discovered->lastModified ?: $source->last_modified,
            'last_fetched_at' => now(),
            'last_success_at' => now(),
            'last_error' => null,
        ])->save();
    }

    private function hostFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return 'feed.local';
        }

        return strtolower(preg_replace('/^www\./', '', $host) ?? $host);
    }

    private function uniqueUsername(string $host, string $feedUrl): string
    {
        $base = Str::slug(str_replace('.', '-', $host), '-');
        $base = $base !== '' ? $base : 'feed';
        $base = mb_substr($base, 0, 40);
        $suffix = substr(hash('sha256', $feedUrl), 0, 8);
        $candidate = $base.'-'.$suffix;

        $n = 0;

        while (Actor::query()
            ->where('preferred_username', $candidate)
            ->where('domain', $host)
            ->exists()) {
            $n++;
            $candidate = $base.'-'.$suffix.$n;
        }

        return mb_substr($candidate, 0, 255);
    }
}
