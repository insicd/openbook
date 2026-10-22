<?php

namespace Tests\Feature;

use App\Application\Services\PostDeliverySource;
use App\Application\Services\PostDeliveryStats;
use App\Application\Services\StatsDataset;
use App\Domain\Posts\Post;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class PostDeliveryStatsTest extends TestCase
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
        $stats = new PostDeliveryStats;
        $source = new PostDeliverySource('relay', 'relay-identity', 'relay.example');
        DB::beginTransaction();
        $post = Post::create(['actor_id' => $actor->id, 'uri' => $actor->uri.'/post', 'body' => 'Example', 'status' => 'published', 'visibility' => 'public', 'published_at' => now()]);
        $stats->record($post, $source);
        $stats->record($post, $source);
        $this->assertSame(0, (int) DB::table('stats_post_delivery_buckets')->sum('count'));
        DB::commit();
        $this->assertSame(2, (int) DB::table('stats_post_delivery_buckets')->sum('count'));
        $post->update(['status' => 'deleted']);
        $actor->update(['remote_suspended' => true]);
        $this->assertSame(2, (int) DB::table('stats_post_delivery_buckets')->sum('count'));
        $total = collect(app(StatsDataset::class)->acquisition()['weeks'])->sum(fn ($week) => array_sum($week['counts']));
        $this->assertSame(2, $total);
    }

    public function test_rollback_discards_the_increment_and_private_posts_do_not_contribute(): void
    {
        $stats = new PostDeliveryStats;
        DB::beginTransaction();
        $stats->record(new Post(['status' => 'published', 'visibility' => 'public']));
        DB::rollBack();
        $stats->record(new Post(['status' => 'published', 'visibility' => 'followers']));
        $this->assertSame(0, (int) DB::table('stats_post_delivery_buckets')->sum('count'));
    }

    public function test_metric_failure_cannot_fail_or_rollback_post_commit(): void
    {
        $actor = $this->createRemoteActor('metricsfailure');
        Schema::drop('stats_post_delivery_buckets');
        $post = DB::transaction(function () use ($actor): Post {
            $post = Post::create(['actor_id' => $actor->id, 'uri' => $actor->uri.'/post', 'body' => 'Must survive', 'status' => 'published', 'visibility' => 'public', 'published_at' => now()]);
            (new PostDeliveryStats)->record($post, new PostDeliverySource('inbox'));

            return $post;
        });
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'body' => 'Must survive']);
        $this->assertSame(0, DB::transactionLevel());
    }
}
