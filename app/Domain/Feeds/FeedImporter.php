<?php

namespace App\Domain\Feeds;

use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Importa le voci di un feed come Post pubblici dell'Actor "feed" collegato.
 */
final class FeedImporter
{
    public function __construct(
        private readonly FeedDiscoverer $discoverer,
        private readonly FeedDocumentParser $parser,
    ) {}

    /**
     * @return int Numero di nuovi post creati
     */
    public function import(Actor $actor, ?string $rawBody = null, int $limit = 0): int
    {
        if (! $actor->isFeed()) {
            return 0;
        }

        $source = $actor->feedSource;

        if ($source === null) {
            return 0;
        }

        $limit = $limit > 0 ? $limit : (int) config('openbook.feeds.import_limit', 40);
        $body = $rawBody;
        $notModified = false;

        try {
            if ($body === null) {
                $response = $this->discoverer->fetch(
                    $source->feed_url,
                    $source->etag,
                    $source->last_modified,
                );

                if ($response->status === 304) {
                    $notModified = true;
                } elseif (! $response->successful()) {
                    throw new RuntimeException('HTTP '.$response->status);
                } else {
                    $body = $response->body;
                    $source->etag = $response->header('ETag') ?: $source->etag;
                    $source->last_modified = $response->header('Last-Modified') ?: $source->last_modified;
                }
            }
        } catch (Throwable $exception) {
            $source->last_fetched_at = now();
            $source->last_error = mb_substr($exception->getMessage(), 0, 1000);
            $source->save();
            Log::info('feeds.fetch_failed', [
                'feed_url' => $source->feed_url,
                'reason' => $exception->getMessage(),
            ]);

            return 0;
        }

        $source->last_fetched_at = now();

        if ($notModified || $body === null || $body === '') {
            $source->last_success_at = now();
            $source->last_error = null;
            $source->save();
            $actor->forceFill(['posts_fetched_at' => now()])->save();

            return 0;
        }

        try {
            $entries = $this->parser->parseEntries($body, $limit);
            $meta = $this->parser->parseMetadata($body);
        } catch (Throwable $exception) {
            $source->last_error = mb_substr($exception->getMessage(), 0, 1000);
            $source->save();

            return 0;
        }

        $created = 0;

        foreach ($entries as $entry) {
            if ($this->upsertEntry($actor, $entry)) {
                $created++;
            }
        }

        $actor->fill([
            'name' => mb_substr($meta['title'], 0, 255),
            'summary' => $meta['summary'] !== null
                ? mb_substr(strip_tags($meta['summary']), 0, 5000)
                : $actor->summary,
            'icon_url' => $meta['icon_url'] ?: $actor->icon_url,
            'posts_fetched_at' => now(),
            'last_fetched_at' => now(),
        ])->save();

        $source->site_url = $meta['site_url'] ?: $source->site_url;
        $source->format = $meta['format'];
        $source->last_success_at = now();
        $source->last_error = null;
        $source->save();

        return $created;
    }

    private function upsertEntry(Actor $actor, FeedEntry $entry): bool
    {
        $existing = Post::query()->where('uri', $entry->uri)->first();

        if ($existing !== null) {
            return false;
        }

        $publishedAt = $entry->publishedAt !== null
            ? Carbon::instance(\DateTimeImmutable::createFromInterface($entry->publishedAt))
            : now();

        Post::query()->create([
            'actor_id' => $actor->id,
            'uri' => $entry->uri,
            'title' => mb_substr($entry->title, 0, 255),
            'body' => mb_substr($entry->body, 0, 50000),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => $publishedAt,
        ]);

        return true;
    }
}
