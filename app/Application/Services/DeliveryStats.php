<?php

namespace App\Application\Services;

use App\Domain\Events\Event;
use App\Domain\Posts\Post;
use App\Federation\Inbox\InboxItem;
use App\Federation\Inbox\RemoteEventObject;
use App\Federation\Inbox\RemotePostObject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

final class DeliveryStats
{
    private ?Connection $writer = null;

    public function recordInbox(array $activity, InboxItem $item): void
    {
        try {
            $object = $activity['object'] ?? null;
            $document = is_array($object) ? (RemoteEventObject::unwrap($object) ?? RemotePostObject::unwrap($object)) : null;
            $uri = is_string($object) ? $object : ($document['id'] ?? (is_array($object) ? ($object['id'] ?? null) : null));
            if (! is_string($uri)) {
                return;
            }
            $event = Event::query()->where('uri', $uri)->first();
            if ($event !== null) {
                $this->recordEvent($event, inbox: $item);

                return;
            }
            $post = Post::query()->where('uri', $uri)->first();
            if ($post !== null) {
                $this->record($post, inbox: $item);
            }
        } catch (Throwable) {
            // Delivery statistics must never fail inbox processing.
        }
    }

    public function record(Post $post, ?DeliverySource $source = null, ?InboxItem $inbox = null): void
    {
        try {
            if ($post->status !== Post::STATUS_PUBLISHED || $post->visibility !== Post::VISIBILITY_PUBLIC || $post->conversation_id !== null) {
                return;
            }
            $this->schedule($source, $inbox, $post->community_id);
        } catch (Throwable) {
            // Metrics must never affect post processing.
        }
    }

    public function recordEvent(Event $event, ?DeliverySource $source = null, ?InboxItem $inbox = null): void
    {
        try {
            if ($event->visibility !== Event::VISIBILITY_PUBLIC || $event->deleted_at !== null) {
                return;
            }
            $this->schedule($source, $inbox);
        } catch (Throwable) {
            // Metrics must never affect event processing.
        }
    }

    private function schedule(?DeliverySource $source, ?InboxItem $inbox, ?string $communityId = null): void
    {
        try {
            $day = CarbonImmutable::now('UTC')->format('Y-m-d');
            DB::connection()->afterCommit(function () use ($day, $communityId, $source, $inbox): void {
                try {
                    // Community privacy is a content boundary, not an author dimension.
                    if ($communityId !== null && ! DB::table('communities')->where('id', $communityId)->where('is_private', false)->exists()) {
                        return;
                    }
                    $source ??= $inbox !== null ? DeliverySource::inbox($inbox) : new DeliverySource;
                    $writer = $this->writer();
                    $writer->transaction(function () use ($writer, $day, $source): void {
                        $writer->table('stats_delivery_sources')->insertOrIgnore([
                            'id' => $source->key(), 'kind' => $source->kind, 'label' => $source->label,
                        ]);
                        $bucket = $writer->table('stats_content_delivery_buckets')->where('day', $day)->where('source_id', $source->key());
                        $writer->table('stats_content_delivery_buckets')->insertOrIgnore(['day' => $day, 'source_id' => $source->key(), 'count' => 0]);
                        $bucket->increment('count');
                    });
                } catch (Throwable) {
                    // Best effort: lose the metric, never retry or undo the content.
                }
            });
        } catch (Throwable) {
            // Even callback registration is outside the critical delivery path.
        }
    }

    private function writer(): Connection
    {
        if ($this->writer !== null) {
            return $this->writer;
        }
        $config = DB::connection()->getConfig();
        unset($config['name'], $config['read']);
        $config['name'] = 'stats_delivery_writer';
        $config['timezone'] = '+00:00';
        $config['options'][PDO::ATTR_PERSISTENT] = false;

        return $this->writer = DB::build($config);
    }
}
