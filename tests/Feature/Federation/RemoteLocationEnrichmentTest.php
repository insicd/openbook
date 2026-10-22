<?php

namespace Tests\Feature\Federation;

use App\Domain\Locations\GeoCity;
use App\Federation\Inbox\RemoteEventIngester;
use App\Federation\Inbox\RemoteNoteUpserter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class RemoteLocationEnrichmentTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public static function contentTypes(): array
    {
        return ['post' => ['Note'], 'event' => ['Event']];
    }

    #[DataProvider('contentTypes')]
    public function test_coordinates_complete_both_content_types_without_changing_the_remote_position(string $type): void
    {
        $this->catalog();
        $location = $this->receive($type, ['name' => 'Kuusamo', 'latitude' => 65.97, 'longitude' => 29.19]);

        $this->assertSame(649924, $location->geo_city_id);
        $this->assertSame('FI', $location->country_code);
        $this->assertSame('Finland', $location->country_name);
        $this->assertSame('Pohjois-Pohjanmaa', $location->admin1_name ?? $location->region);
        $this->assertSame(65.97, $location->latitude);
        $this->assertSame(29.19, $location->longitude);
        $this->assertSame('Kuusamo', $location->name);
        if ($type === 'Event') {
            $this->assertNull($location->address);
        }
    }

    #[DataProvider('contentTypes')]
    public function test_country_names_in_code_fields_are_normalized_even_without_the_catalog(string $type): void
    {
        config(['openbook.locations.catalog_ready' => false]);
        foreach (['Suomi', 'Finland', 'Finlandia', 'fi'] as $country) {
            $location = $this->receive($type, [
                'name' => 'Kuusamo', 'countryCode' => $country,
                'address' => ['addressCountry' => $country],
            ]);
            $this->assertSame('FI', $location->country_code);
            $this->assertSame($type === 'Event' && $country === 'Finlandia' ? 'Finlandia' : 'Finland', $location->country_name);
            $this->assertNull($location->geo_city_id);
        }
    }

    #[DataProvider('contentTypes')]
    public function test_country_names_fill_a_missing_code_and_explicit_regions_are_preserved(string $type): void
    {
        $this->catalog();
        $location = $this->receive($type, [
            'name' => 'Kuusamo', 'country' => 'Suomi', 'region' => 'Remote region',
            'latitude' => 65.97, 'longitude' => 29.19,
            'address' => ['addressCountryName' => 'Suomi', 'addressRegion' => 'Remote region'],
        ]);
        $this->assertSame('FI', $location->country_code);
        $this->assertSame('Suomi', $location->country_name);
        $this->assertSame('Remote region', $location->admin1_name ?? $location->region);
        $this->assertSame(649924, $location->geo_city_id);
    }

    #[DataProvider('contentTypes')]
    public function test_address_matching_does_not_invent_coordinates(string $type): void
    {
        $this->catalog();
        // A country component must not be mistaken for a homonymous city.
        $other = GeoCity::query()->firstOrFail()->replicate();
        $other->forceFill(['geoname_id' => 999998, 'name' => 'Finland', 'ascii_name' => 'Finland',
            'country_code' => 'US', 'country_name' => 'United States', 'population' => 999999])->save();
        $location = $this->receive($type, ['name' => 'Kuusamo, Finland', 'address' => 'Kuusamo, Finland']);
        $this->assertSame(649924, $location->geo_city_id);
        $this->assertSame('FI', $location->country_code);
        $this->assertNull($location->latitude);
        $this->assertNull($location->longitude);
    }

    #[DataProvider('contentTypes')]
    public function test_complete_remote_fields_still_get_a_catalog_link(string $type): void
    {
        $this->catalog();
        $location = $this->receive($type, [
            'name' => 'Kuusamo', 'countryCode' => 'FI', 'country' => 'Suomi',
            'region' => 'Remote region', 'latitude' => 65.97, 'longitude' => 29.19,
            'address' => ['addressLocality' => 'Kuusamo', 'addressCountry' => 'FI',
                'addressCountryName' => 'Suomi', 'addressRegion' => 'Remote region'],
        ]);
        $this->assertSame(649924, $location->geo_city_id);
        $this->assertSame('FI', $location->country_code);
        $this->assertSame('Suomi', $location->country_name);
        $this->assertSame('Remote region', $location->admin1_name ?? $location->region);
    }

    #[DataProvider('contentTypes')]
    public function test_country_restricts_address_matching_when_cities_have_the_same_name(string $type): void
    {
        $this->catalog();
        $other = GeoCity::query()->firstOrFail()->replicate();
        $other->forceFill(['geoname_id' => 999999, 'country_code' => 'US', 'country_name' => 'United States', 'population' => 999999])->save();
        $location = $this->receive($type, [
            'name' => 'Kuusamo', 'country' => 'Finland',
            'address' => ['addressLocality' => 'Kuusamo', 'addressCountryName' => 'Finland'],
        ]);
        $this->assertSame(649924, $location->geo_city_id);
        $this->assertSame('FI', $location->country_code);
        $this->assertNull($location->latitude);
    }

    #[DataProvider('contentTypes')]
    public function test_distant_coordinates_do_not_fall_back_to_the_name(string $type): void
    {
        $this->catalog();
        $location = $this->receive($type, [
            'name' => 'Kuusamo, Finland', 'address' => 'Kuusamo, Finland',
            'latitude' => 0, 'longitude' => 0,
        ]);
        $this->assertNull($location->geo_city_id);
        $this->assertNull($location->country_code);
        $this->assertSame(0.0, $location->latitude);
    }

    #[DataProvider('contentTypes')]
    public function test_unknown_country_labels_are_preserved_without_becoming_codes(string $type): void
    {
        config(['openbook.locations.catalog_ready' => false]);
        $location = $this->receive($type, [
            'name' => 'Somewhere', 'countryCode' => 'Atlantis',
            'address' => ['addressCountry' => 'Atlantis'],
        ]);
        $this->assertNull($location->country_code);
        $this->assertSame('ATLANTIS', $location->country_name);
    }

    public function test_web_refresh_enriches_the_same_version_but_rejects_an_older_document(): void
    {
        $this->catalog();
        $location = $this->receive('Note', ['name' => 'Kuusamo', 'latitude' => 65.97, 'longitude' => 29.19]);
        $location->forceFill(['geo_city_id' => null, 'country_code' => null, 'admin1_name' => null])->save();
        $post = $location->post;
        $version = now()->subHour()->startOfSecond();
        $post->forceFill(['remote_updated_at' => $version])->save();
        $document = [
            'id' => $post->uri, 'type' => 'Note', 'attributedTo' => $post->actor->uri,
            'content' => 'Current version', 'published' => $post->published_at->toAtomString(),
            'updated' => $version->toAtomString(), 'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'location' => ['type' => 'Place', 'name' => 'Kuusamo', 'country' => 'Finland',
                'latitude' => 65.97, 'longitude' => 29.19],
        ];
        Http::fake([$post->uri => Http::response($document)]);
        $this->actingAs($this->createFullAccount('georefresh'))
            ->post(route('posts.fetch_updates', $post))->assertRedirect();
        $this->assertSame(649924, $location->fresh()->geo_city_id);
        $this->assertSame('FI', $location->fresh()->country_code);

        $document['updated'] = $version->copy()->subHour()->toAtomString();
        $document['content'] = 'Obsolete version';
        $document['location'] = ['type' => 'Place', 'name' => 'Other place', 'countryCode' => 'NO'];
        Http::fake([$post->uri => Http::response($document)]);
        $this->post(route('posts.fetch_updates', $post))->assertRedirect();
        $this->assertSame('Current version', $post->fresh()->body);
        $this->assertSame(649924, $location->fresh()->geo_city_id);
        $this->assertSame('FI', $location->fresh()->country_code);
        $this->assertSame('Kuusamo', $location->fresh()->name);
    }

    private function catalog(): void
    {
        config(['openbook.locations.catalog_ready' => true]);
        GeoCity::query()->create([
            'geoname_id' => 649924, 'name' => 'Kuusamo', 'ascii_name' => 'Kuusamo',
            'latitude' => 65.96667, 'longitude' => 29.18333,
            'latitude_bucket' => 65, 'longitude_bucket' => 29,
            'country_code' => 'FI', 'country_name' => 'Finland',
            'admin1_name' => 'Pohjois-Pohjanmaa', 'feature_code' => 'PPLA',
            'population' => 15000, 'catalog_batch' => '00000000-0000-0000-0000-000000000001',
        ]);
    }

    private function receive(string $type, array $place): Model
    {
        $actor = $this->createRemoteActor('geo'.Str::lower(Str::random(8)));
        $document = [
            'id' => $actor->uri.'/contents/1', 'type' => $type, 'attributedTo' => $actor->uri,
            'name' => 'Remote event', 'startTime' => now()->addDay()->toAtomString(),
            'published' => now()->toAtomString(), 'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'location' => ['type' => 'Place', ...$place],
        ];
        $content = $type === 'Event'
            ? app(RemoteEventIngester::class)->ingest($document, $actor, 'Create', trackDelivery: false)
            : app(RemoteNoteUpserter::class)->upsertPost($document, $document['id'], $actor, 'Post', Carbon::now(), notifyMentions: false, trackDelivery: false);

        return $content->location()->firstOrFail();
    }
}
