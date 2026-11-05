<?php

namespace Tests\Feature;

use App\Application\Services\DeliverySource;
use App\Domain\Federation\Relay;
use App\Federation\Inbox\InboxItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeliverySourceConstraintTest extends TestCase
{
    use RefreshDatabase;

    private function source(string $name): string
    {
        $id = hash('sha256', $name);
        DB::table('stats_delivery_sources')->insert(['id' => $id, 'kind' => 'relay', 'label' => $name]);

        return $id;
    }

    private function bucket(string $source, string $day = '2026-11-03'): void
    {
        DB::table('stats_content_delivery_buckets')->insert(['source_id' => $source, 'day' => $day, 'count' => 7]);
    }

    public function test_deleting_a_source_removes_its_history_and_preserves_other_sources(): void
    {
        $deleted = $this->source('deleted.example');
        $retained = $this->source('retained.example');
        $this->bucket($deleted);
        $this->bucket($deleted, '2026-11-02');
        $this->bucket($retained);

        DB::table('stats_delivery_sources')->where('id', $deleted)->delete();

        $this->assertDatabaseMissing('stats_content_delivery_buckets', ['source_id' => $deleted]);
        $this->assertDatabaseHas('stats_delivery_sources', ['id' => $retained]);
        $this->assertDatabaseHas('stats_content_delivery_buckets', ['source_id' => $retained, 'count' => 7]);
    }

    public function test_an_orphan_bucket_cannot_be_inserted(): void
    {
        $this->expectException(QueryException::class);
        $this->bucket(hash('sha256', 'missing'));
    }

    public function test_deleting_a_relay_keeps_its_statistical_source_and_history(): void
    {
        $relay = Relay::query()->create([
            'protocol' => Relay::PROTOCOL_MASTODON, 'state' => Relay::STATE_IDLE,
            'actor_uri' => 'https://relay.example/actor', 'inbox_url' => 'https://relay.example/inbox',
            'inbox_url_hash' => hash('sha256', 'https://relay.example/inbox'),
        ]);
        $item = new InboxItem;
        $item->setRelation('relay', $relay);
        $source = DeliverySource::inbox($item);
        DB::table('stats_delivery_sources')->insert(['id' => $source->key(), 'kind' => $source->kind, 'label' => $source->label]);
        $this->bucket($source->key());

        $relay->delete();

        $this->assertDatabaseHas('stats_delivery_sources', ['id' => $source->key()]);
        $this->assertDatabaseHas('stats_content_delivery_buckets', ['source_id' => $source->key(), 'count' => 7]);
    }

    public function test_migration_round_trip_preserves_existing_counters(): void
    {
        $source = $this->source('existing.example');
        $this->bucket($source);
        $migration = require database_path('migrations/2026_11_03_000001_constrain_delivery_bucket_sources.php');
        $migration->down();
        $this->assertDatabaseHas('stats_content_delivery_buckets', ['source_id' => $source, 'count' => 7]);
        $migration->up();
        $this->assertDatabaseHas('stats_content_delivery_buckets', ['source_id' => $source, 'count' => 7]);
        DB::table('stats_delivery_sources')->where('id', $source)->delete();
        $this->assertDatabaseCount('stats_content_delivery_buckets', 0);
    }

    public function test_migration_removes_existing_orphans_and_preserves_valid_counters(): void
    {
        $migration = require database_path('migrations/2026_11_03_000001_constrain_delivery_bucket_sources.php');
        $migration->down();
        $retained = $this->source('retained.example');
        $this->bucket($retained);
        $missing = hash('sha256', 'missing');
        $this->bucket($missing);
        $this->bucket($missing, '2026-11-02');
        $migration->up();

        $this->assertDatabaseMissing('stats_content_delivery_buckets', ['source_id' => $missing]);
        $this->assertDatabaseHas('stats_delivery_sources', ['id' => $retained]);
        $this->assertDatabaseHas('stats_content_delivery_buckets', ['source_id' => $retained, 'count' => 7]);
        $this->assertDatabaseCount('stats_content_delivery_buckets', 1);
    }

    public function test_migration_removes_all_buckets_when_no_sources_exist(): void
    {
        $migration = require database_path('migrations/2026_11_03_000001_constrain_delivery_bucket_sources.php');
        $migration->down();
        $this->bucket(hash('sha256', 'missing'));
        $migration->up();

        $this->assertDatabaseCount('stats_content_delivery_buckets', 0);
    }
}
