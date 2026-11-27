<?php

namespace Tests\Feature\Rest;

use App\Application\Services\CommunityRegistrar;
use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\UsesPassportKeys;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase, UsesPassportKeys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        Passport::actingAs($this->createFullAccount('searchviewer'), ['api'], 'rest');
        $this->withToken('test-token');
        Http::preventStrayRequests();
    }

    public function test_search_includes_all_searchable_profile_types_in_web_search_order(): void
    {
        $person = $this->createFullAccount('garden');
        $person->profile->update(['display_name' => 'Garden Person']);
        $bot = $this->remoteActor('gardenbot', Actor::TYPE_APPLICATION);
        $feed = $this->remoteActor('gardenfeed', Actor::TYPE_FEED);
        $community = app(CommunityRegistrar::class)->register($person, [
            'slug' => 'gardengroup',
            'name' => 'Garden Group',
        ]);

        $response = $this->get('/rest/v1/search?q=garden')->assertOk()->assertJsonCount(4, 'profiles');
        $this->assertSame(
            [$person->actor->id, $bot->id, $feed->id, $community->actor_id],
            array_column($response->json('profiles'), 'personId'),
        );

        $this->get('/rest/v1/search?q=garden&type=profile')->assertOk()->assertExactJson($response->json());
        $this->get('/rest/v1/search?q='.rawurlencode('@gardenbot@social.example'))->assertOk()
            ->assertExactJson(['profiles' => [[
                'personId' => $bot->id,
                'personUsername' => 'gardenbot',
                'personDomain' => 'social.example',
                'personName' => 'Gardenbot',
            ]]]);
        Http::assertNothingSent();
    }

    public function test_search_only_exposes_profiles_visible_in_the_web_search(): void
    {
        $hidden = $this->createFullAccount('gardener');
        $hidden->settings->update(['discoverable' => false]);
        $remote = $this->remoteActor('gardenhidden', Actor::TYPE_PERSON);
        $remote->update(['discoverable' => false]);
        $suspended = $this->remoteActor('gardensuspended', Actor::TYPE_PERSON);
        $suspended->update(['remote_suspended' => true]);
        app(CommunityRegistrar::class)->register($hidden, [
            'slug' => 'gardensecret',
            'name' => 'Garden Secret',
            'is_private' => true,
        ]);

        $this->get('/rest/v1/search?q=garden')->assertOk()->assertExactJson(['profiles' => []]);
    }

    public function test_search_uses_the_instance_limits_and_validates_query_parameters(): void
    {
        config(['openbook.search.min_length' => 3, 'openbook.search.per_section' => 1]);
        $first = $this->remoteActor('garden', Actor::TYPE_PERSON);
        $this->remoteActor('gardener', Actor::TYPE_PERSON);

        $this->get('/rest/v1/search?q=garden')->assertOk()->assertJsonCount(1, 'profiles')
            ->assertJsonPath('profiles.0.personId', $first->id);
        foreach (['', '?q=%20%20', '?q=ab', '?q[]=garden', '?q=garden&type=person'] as $query) {
            $this->get('/rest/v1/search'.$query)->assertUnprocessable()
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 422)
                ->assertJsonStructure(['errors' => [$query === '?q=garden&type=person' ? 'type' : 'q']]);
        }
    }

    public function test_search_revalidates_visibility_and_requires_bearer_credentials(): void
    {
        $person = $this->remoteActor('garden', Actor::TYPE_PERSON);
        $url = '/rest/v1/search?q=garden';

        $etag = $this->get($url)->assertOk()->assertHeader('Vary', 'Authorization')
            ->assertJsonPath('profiles.0.personId', $person->id)->headers->get('ETag');
        $this->assertNotNull($etag);
        $this->withHeader('If-None-Match', $etag)->get($url)->assertNotModified();

        $person->update(['discoverable' => false]);
        $this->get($url)->assertOk()->assertExactJson(['profiles' => []]);
        $this->withoutHeader('Authorization')->get($url)->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer');
    }

    private function remoteActor(string $username, string $type): Actor
    {
        return Actor::query()->create([
            'type' => $type,
            'is_local' => false,
            'preferred_username' => $username,
            'domain' => 'social.example',
            'uri' => 'https://social.example/users/'.$username,
            'name' => ucfirst($username),
            'discoverable' => true,
            'status' => Actor::STATUS_ACTIVE,
        ]);
    }
}
