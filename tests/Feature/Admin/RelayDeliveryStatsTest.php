<?php

namespace Tests\Feature\Admin;

use App\Application\Queries\RelayDeliveryStatsQuery;
use App\Application\Services\DeliverySource;
use App\Domain\Federation\Relay;
use App\Federation\Inbox\InboxItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class RelayDeliveryStatsTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function relay(string $path, ?string $actorUri = null): Relay
    {
        $url = 'https://relay.example/'.$path;

        return Relay::create(['protocol' => Relay::PROTOCOL_MASTODON, 'actor_uri' => $actorUri,
            'inbox_url' => $url, 'inbox_url_hash' => hash('sha256', $url),
            'state' => Relay::STATE_ACCEPTED, 'receive_enabled' => true]);
    }

    private function bucket(DeliverySource $source, string $day, int $count): void
    {
        DB::table('stats_delivery_sources')->insertOrIgnore(['id' => $source->key(), 'kind' => $source->kind, 'label' => $source->label]);
        DB::table('stats_content_delivery_buckets')->insert(['source_id' => $source->key(), 'day' => $day, 'count' => $count]);
    }

    public function test_totals_use_utc_days_and_exact_relay_identity_in_one_query(): void
    {
        config(['app.timezone' => 'America/Los_Angeles']);
        $this->travelTo(CarbonImmutable::parse('2026-11-08T00:30:00Z'));
        $first = $this->relay('one/inbox', 'https://relay.example/actor/one');
        $second = $this->relay('two/inbox');
        $empty = $this->relay('empty/inbox');
        $source = DeliverySource::forRelay($first);
        $this->bucket($source, '2026-11-08', 4);
        $this->bucket($source, '2026-11-07', 6);
        $this->bucket($source, '2026-11-02', 9);
        $this->bucket($source, '2026-11-01', 1000);
        $this->bucket($source, '2026-11-09', 2000);
        $this->bucket(DeliverySource::forRelay($second), '2026-11-08', 18);
        $this->bucket(DeliverySource::forRelay($second), '2026-11-03', 2);
        $this->bucket(new DeliverySource('inbox'), '2026-11-08', 4000);
        $this->bucket(new DeliverySource('relay', 'unrelated', 'relay.example'), '2026-11-08', 8000);
        // The receive path and admin lookup must use exactly the same source key.
        $item = new InboxItem;
        $item->setRelation('relay', $first);
        $this->assertSame(DeliverySource::inbox($item)->key(), $source->key());
        DB::enableQueryLog();
        DB::flushQueryLog();
        $counts = app(RelayDeliveryStatsQuery::class)->forRelays([$first, $second, $empty]);
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame(['today' => 4, 'lastSevenDays' => 19], $counts[$first->id]);
        $this->assertSame(['today' => 18, 'lastSevenDays' => 20], $counts[$second->id]);
        $this->assertSame(['today' => 0, 'lastSevenDays' => 0], $counts[$empty->id]);
    }

    public function test_admin_page_places_totals_below_exchange_status_and_defaults_to_zero(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-08T12:00:00Z'));
        $admin = $this->createFullAccount('relaystatsadmin');
        $admin->forceFill(['is_admin' => true])->save();
        $relay = $this->relay('admin/inbox');
        $empty = $this->relay('empty/inbox');
        $this->bucket(DeliverySource::forRelay($relay), '2026-11-08', 7);
        $this->bucket(DeliverySource::forRelay($relay), '2026-11-02', 11);
        $response = $this->actingAs($admin)->get(route('admin.relays.index'))->assertOk();
        $response->assertViewHas('deliveryCounts', fn ($counts) => $counts[$relay->id] === ['today' => 7, 'lastSevenDays' => 18]
            && $counts[$empty->id] === ['today' => 0, 'lastSevenDays' => 0]);
        $response->assertSeeInOrder([__('openbook.admin.relays.last_failure'),
            __('openbook.admin.relays.received_today', ['count' => 7]),
            __('openbook.admin.relays.received_last_seven_days', ['count' => 18])]);
        $response->assertSee(__('openbook.admin.relays.received_today', ['count' => 0]));
    }

    public function test_empty_page_does_not_query_delivery_statistics(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame([], app(RelayDeliveryStatsQuery::class)->forRelays([]));
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }
}
