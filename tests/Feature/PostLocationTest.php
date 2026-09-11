<?php

namespace Tests\Feature;

use App\Application\Services\PostComposer;
use App\Domain\Locations\GeoCity;
use App\Domain\Posts\Post;
use App\Federation\Serialization\NoteSerializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class PostLocationTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_location_controls_are_hidden_until_the_catalog_is_ready(): void
    {
        $user = $this->createFullAccount('catalogtoggle');

        $this->actingAs($user)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertDontSee('data-location-picker', false);

        config()->set('openbook.locations.catalog_ready', true);

        $this->get(route('feed.index'))
            ->assertOk()
            ->assertSee('data-location-picker', false);
    }

    public function test_a_catalog_city_is_snapshotted_when_a_post_is_created(): void
    {
        $user = $this->createFullAccount('locatedpost');
        $city = $this->city(658225, 'Helsinki', 'Uusimaa', 'FI', 'Finland');

        $this->actingAs($user)->post(route('posts.store'), [
            'body' => 'Post con posizione.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'location_id' => $city->geoname_id,
            'location_label' => $city->label(),
        ])->assertRedirect();

        $post = Post::query()->sole();
        $this->assertSame('Helsinki, Uusimaa, Finland', $post->location?->label());
        $this->assertSame(60.1695, $post->location?->latitude);
        $this->assertSame(24.9354, $post->location?->longitude);

        $place = NoteSerializer::forPost($post)['location'];
        $this->assertSame('Place', $place['type']);
        $this->assertSame('Helsinki, Uusimaa, Finland', $place['name']);
        $this->assertSame(60.1695, $place['latitude']);
        $this->assertSame(24.9354, $place['longitude']);
        $this->assertSame('Finland', $place['country']);

        $this->actingAs($user)
            ->get(route('posts.show', $post))
            ->assertOk()
            ->assertSee('Helsinki, Uusimaa, Finland')
            ->assertDontSee('60.1695')
            ->assertDontSee('24.9354');
    }

    public function test_an_existing_location_can_be_changed_and_removed(): void
    {
        $user = $this->createFullAccount('editlocation');
        $helsinki = $this->city(658225, 'Helsinki', 'Uusimaa', 'FI', 'Finland');
        $denpasar = $this->city(1645528, 'Denpasar', 'Bali', 'ID', 'Indonesia');
        $post = app(PostComposer::class)->compose($user->actor, [
            'body' => 'Prima versione.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'location_id' => $helsinki->geoname_id,
        ]);

        $this->actingAs($user)->put(route('posts.update', $post), [
            'body' => 'Seconda versione.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'location_id' => $denpasar->geoname_id,
            'location_label' => $denpasar->label(),
        ])->assertRedirect();
        $this->assertSame('Denpasar', $post->location()->sole()->name);

        $this->actingAs($user)->put(route('posts.update', $post), [
            'body' => 'Terza versione.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'location_id' => null,
            'location_label' => null,
        ])->assertRedirect();
        $this->assertDatabaseMissing('post_locations', ['post_id' => $post->id]);
    }

    public function test_deleting_a_post_also_removes_its_location(): void
    {
        $user = $this->createFullAccount('deletelocation');
        $city = $this->city(658225, 'Helsinki', 'Uusimaa', 'FI', 'Finland');
        $post = app(PostComposer::class)->compose($user->actor, [
            'body' => 'Post da eliminare.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'location_id' => $city->geoname_id,
        ]);

        $this->actingAs($user)->delete(route('posts.destroy', $post))->assertRedirect();

        $this->assertDatabaseMissing('post_locations', ['post_id' => $post->id]);
    }

    private function city(int $id, string $name, string $admin1, string $countryCode, string $countryName): GeoCity
    {
        return GeoCity::query()->create([
            'geoname_id' => $id,
            'name' => $name,
            'ascii_name' => $name,
            'latitude' => $name === 'Helsinki' ? 60.1695 : -8.65,
            'longitude' => $name === 'Helsinki' ? 24.9354 : 115.2167,
            'latitude_bucket' => $name === 'Helsinki' ? 60 : -9,
            'longitude_bucket' => $name === 'Helsinki' ? 24 : 115,
            'country_code' => $countryCode,
            'country_name' => $countryName,
            'admin1_code' => '01',
            'admin1_name' => $admin1,
            'feature_code' => 'PPLA',
            'population' => 600000,
            'catalog_batch' => '847ef4c2-1486-4a4f-a234-345436782f5f',
        ]);
    }
}
