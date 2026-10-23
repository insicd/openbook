<?php

namespace Tests\Feature;

use App\Application\Services\DeliverySource;
use App\Application\Services\DeliveryStats;
use App\Application\Services\StatsDataset;
use App\Domain\Events\Event;
use App\Domain\Federation\Relay;
use App\Domain\Posts\Post;
use App\Federation\Inbox\InboxActivityProcessor;
use App\Federation\Inbox\InboxItem;
use App\Federation\Inbox\RemoteEventIngester;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class DeliveryStatsTest extends TestCase
{
    use CreatesRemoteActors;

    private string $databaseFile;

    protected function setUp(): void
    {
        parent::setUp();
        // A file allows the metrics writer to use a genuinely separate connection.
        $this->databaseFile = tempnam(sys_get_temp_dir(), 'openbook-delivery-');
        config(['database.default' => 'delivery_test', 'database.connections.delivery_test' => [
            'driver' => 'sqlite', 'database' => $this->databaseFile, 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        Artisan::call('migrate', ['--database' => 'delivery_test', '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('delivery_test');
        parent::tearDown();
        @unlink($this->databaseFile);
    }

    public function test_repeated_deliveries_count_after_the_outer_commit_and_survive_later_changes(): void
    {
        $actor = $this->createRemoteActor('deliveries');
        $stats = new DeliveryStats;
        $source = new DeliverySource('relay', 'relay-identity', 'relay.example');
        DB::beginTransaction();
        $post = Post::create(['actor_id' => $actor->id, 'uri' => $actor->uri.'/post', 'body' => 'Example', 'status' => 'published', 'visibility' => 'public', 'published_at' => now()]);
        $stats->record($post, $source);
        $stats->record($post, $source);
        $this->assertSame(0, (int) DB::table('stats_content_delivery_buckets')->sum('count'));
        DB::commit();
        $this->assertSame(2, (int) DB::table('stats_content_delivery_buckets')->sum('count'));
        $post->update(['status' => 'deleted']);
        $actor->update(['remote_suspended' => true]);
        $this->assertSame(2, (int) DB::table('stats_content_delivery_buckets')->sum('count'));
        $total = collect(app(StatsDataset::class)->acquisition()['weeks'])->sum(fn ($week) => array_sum($week['counts']));
        $this->assertSame(2, $total);
    }

    public function test_rollback_discards_the_increment_and_private_posts_do_not_contribute(): void
    {
        $stats = new DeliveryStats;
        DB::beginTransaction();
        $stats->record(new Post(['status' => 'published', 'visibility' => 'public']));
        DB::rollBack();
        $stats->record(new Post(['status' => 'published', 'visibility' => 'followers']));
        $this->assertSame(0, (int) DB::table('stats_content_delivery_buckets')->sum('count'));
    }

    public function test_metric_failure_cannot_fail_or_rollback_post_commit(): void
    {
        $actor = $this->createRemoteActor('metricsfailure');
        Schema::drop('stats_content_delivery_buckets');
        $post = DB::transaction(function () use ($actor): Post {
            $post = Post::create(['actor_id' => $actor->id, 'uri' => $actor->uri.'/post', 'body' => 'Must survive', 'status' => 'published', 'visibility' => 'public', 'published_at' => now()]);
            (new DeliveryStats)->record($post, new DeliverySource('inbox'));

            return $post;
        });
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'body' => 'Must survive']);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_event_deliveries_count_repeats_after_commit_and_preserve_post_history(): void
    {
        $actor = $this->createRemoteActor('eventdeliveries');
        $doc = ['id' => $actor->uri.'/event', 'type' => 'Event', 'name' => 'Example', 'attributedTo' => $actor->uri, 'startTime' => now()->addMonth()->toIso8601String(), 'to' => ['https://www.w3.org/ns/activitystreams#Public']];
        DB::beginTransaction();
        $event = app(RemoteEventIngester::class)->ingest($doc, $actor, 'Create', delivery: new DeliverySource('fetch'));
        $this->assertNotNull($event);
        $this->assertSame(0, (int) DB::table('stats_content_delivery_buckets')->sum('count'));
        DB::commit();
        app(RemoteEventIngester::class)->ingest($doc, $actor, 'Create', delivery: new DeliverySource('fetch'));
        $this->assertSame(2, (int) DB::table('stats_content_delivery_buckets')->sum('count'));
        (new DeliveryStats)->record(new Post(['status' => 'published', 'visibility' => 'public']), new DeliverySource('fetch'));
        $this->assertSame(1, DB::table('stats_content_delivery_buckets')->count());
        $event->update(['status' => 'cancelled']);
        $this->assertSame(3, (int) DB::table('stats_content_delivery_buckets')->sum('count'));
        $this->assertSame('content_deliveries', app(StatsDataset::class)->acquisition()['unit']);
    }

    public function test_repeated_relay_announces_of_known_posts_and_events_count_once_per_delivery(): void
    {
        $actor = $this->createRemoteActor('relaydeliveries');
        $post = Post::create(['actor_id' => $actor->id, 'uri' => $actor->uri.'/post', 'body' => 'Known post', 'status' => 'published', 'visibility' => 'public', 'published_at' => now()]);
        $event = Event::create(['actor_id' => $actor->id, 'uri' => $actor->uri.'/event', 'name' => 'Known event', 'visibility' => 'public', 'status' => 'scheduled', 'published_at' => now(), 'start_at' => now()->addMonth()]);
        $relay = new Relay(['state' => 'accepted', 'receive_enabled' => true, 'inbox_url' => 'https://relay.example/inbox']);
        foreach ([$post->uri, $event->uri] as $uri) {
            $item = new InboxItem(['actor_uri' => $actor->uri, 'activity_type' => 'Announce', 'payload' => json_encode(['id' => $uri.'/announce', 'type' => 'Announce', 'actor' => $actor->uri, 'object' => $uri])]);
            $item->setRelation('relay', $relay);
            $item->setRelation('targetActor', null);
            for ($delivery = 0; $delivery < 2; $delivery++) {
                $this->assertSame(InboxItem::STATUS_PROCESSED, app(InboxActivityProcessor::class)->process($item));
            }
        }
        $this->assertSame(1, DB::table('stats_content_delivery_buckets')->count());
        $this->assertSame(4, (int) DB::table('stats_content_delivery_buckets')->sum('count'));
        $this->assertDatabaseHas('stats_delivery_sources', ['kind' => 'relay', 'label' => 'relay.example']);
    }

    public function test_private_event_rollback_and_metric_failure_do_not_affect_event_import(): void
    {
        $stats = new DeliveryStats;
        DB::beginTransaction();
        $stats->recordEvent(new Event(['visibility' => 'public']));
        DB::rollBack();
        $stats->recordEvent(new Event(['visibility' => 'followers']));
        $this->assertSame(0, (int) DB::table('stats_content_delivery_buckets')->sum('count'));
        Schema::drop('stats_content_delivery_buckets');
        $actor = $this->createRemoteActor('eventfailure');
        $doc = ['id' => $actor->uri.'/event', 'type' => 'Event', 'name' => 'Must survive', 'attributedTo' => $actor->uri, 'startTime' => now()->addMonth()->toIso8601String(), 'to' => ['https://www.w3.org/ns/activitystreams#Public']];
        $event = app(RemoteEventIngester::class)->ingest($doc, $actor, 'Create');
        $this->assertNotNull($event);
        $this->assertDatabaseHas('events', ['id' => $event->id, 'name' => 'Must survive']);
    }
}
