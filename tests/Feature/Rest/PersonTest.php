<?php

namespace Tests\Feature\Rest;

use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\UsesPassportKeys;
use Tests\TestCase;

class PersonTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase, UsesPassportKeys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        Passport::actingAs($this->createFullAccount('restviewer'), ['api'], 'rest');
        $this->withHeader('Authorization', 'Bearer test-token');
    }

    private function remote(string $username, array $attributes = []): Actor
    {
        return Actor::query()->create(array_merge([
            'type' => Actor::TYPE_PERSON,
            'is_local' => false,
            'preferred_username' => $username,
            'domain' => 'remote.example',
            'uri' => 'https://remote.example/users/'.$username,
            'name' => ucfirst($username),
            'status' => Actor::STATUS_ACTIVE,
            'discoverable' => true,
            'indexable' => false,
            'published_at' => now()->subYears(2),
        ], $attributes));
    }

    private function url(Actor $actor): string
    {
        return '/rest/v1/persons/'.$actor->id;
    }

    public function test_an_authenticated_user_can_read_a_local_person_without_a_wrapper_or_private_fields(): void
    {
        $user = $this->createFullAccount('localperson');
        $user->forceFill(['is_admin' => true, 'is_moderator' => true])->save();
        $user->profile->update([
            'display_name' => 'Local Person',
            'bio' => '**Source text**',
            'links' => [['label' => 'Website', 'url' => 'https://example.org']],
        ]);
        $follower = $this->remote('follower');
        Follow::query()->create(['follower_id' => $follower->id, 'following_id' => $user->actor->id, 'status' => 'accepted', 'requested_at' => now(), 'accepted_at' => now()]);
        Follow::query()->create(['follower_id' => $user->actor->id, 'following_id' => $follower->id, 'status' => 'pending', 'requested_at' => now()]);

        $response = $this->get($this->url($user->actor));

        $response->assertOk()->assertJsonPath('personId', $user->actor->id)
            ->assertJsonPath('personType', 'person')
            ->assertJsonPath('personPublishedAt', $user->created_at->toISOString())
            ->assertJsonPath('personProfile.personName', 'Local Person')
            ->assertJsonPath('personProfile.personSummary', '**Source text**')
            ->assertJsonPath('personProfile.personFields.0.value', 'https://example.org')
            ->assertJsonPath('personFollowersCount', 1)
            ->assertJsonPath('personFollowingCount', 0)
            ->assertJsonPath('personRoles', ['admin', 'moderator'])
            ->assertHeader('Vary', 'Authorization');
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        foreach (['data', 'email', 'password', 'remember_token', 'user', 'key', 'endpoints', 'personCreatedAt', 'personUpdatedAt'] as $field) {
            $this->assertArrayNotHasKey($field, $response->json());
        }
        $this->assertNull($response->headers->get('Last-Modified'));
    }

    public function test_an_authenticated_user_can_read_cached_remote_data_without_network_requests(): void
    {
        Http::preventStrayRequests();
        $destination = $this->remote('destination');
        $remote = $this->remote('remote', [
            'type' => Actor::TYPE_APPLICATION,
            'summary' => '<p>Remote source</p>',
            'icon_url' => 'https://remote.example/avatar.png',
            'followers_count' => 32,
            'following_count' => null,
            'remote_suspended' => true,
            'custom_emojis' => [':wave:' => 'https://remote.example/wave.png'],
            'also_known_as' => ['https://old.example/users/remote'],
            'moved_to_actor_id' => $destination->id,
        ]);
        Follow::query()->create(['follower_id' => $remote->id, 'following_id' => $destination->id, 'status' => 'accepted', 'requested_at' => now(), 'accepted_at' => now()]);

        $this->get($this->url($remote))->assertOk()
            ->assertJsonPath('personType', 'application')
            ->assertJsonPath('personStatus', 'inactive')
            ->assertJsonPath('personAttributes.personSuspended', true)
            ->assertJsonPath('personAttributes.personMovedTo.personId', $destination->id)
            ->assertJsonPath('personProfile.personSummary', 'Remote source')
            ->assertJsonPath('personFollowersCount', 32)
            ->assertJsonPath('personFollowingCount', 1)
            ->assertJsonMissingPath('personRoles');
        Http::assertNothingSent();
        $this->assertTrue($remote->fresh()->remote_suspended);
    }

    public function test_missing_invalid_deleted_and_non_person_resources_return_problem_details(): void
    {
        $actors = [
            $this->remote('deleted', ['status' => Actor::STATUS_DELETED]),
            $this->remote('group', ['type' => Actor::TYPE_GROUP]),
            $this->remote('feed', ['type' => Actor::TYPE_FEED]),
            $this->remote('relay', ['is_local' => true, 'type' => Actor::TYPE_APPLICATION]),
        ];
        $urls = array_map(fn (Actor $actor): string => $this->url($actor), $actors);
        $urls[] = '/rest/v1/persons/'.Str::uuid();
        $urls[] = '/rest/v1/persons/not-a-uuid';
        foreach ($urls as $url) {
            $this->get($url)->assertNotFound()
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 404)
                ->assertJsonMissingPath('trace');
        }
    }

    public function test_local_suspended_and_disabled_profiles_are_not_exposed_by_the_prototype(): void
    {
        $user = $this->createFullAccount('hiddenperson');
        foreach ([Actor::STATUS_SUSPENDED, Actor::STATUS_BLOCKED] as $status) {
            $user->actor->update(['status' => $status]);
            $this->get($this->url($user->actor))->assertNotFound();
        }
    }

    public function test_cache_validation_tracks_profile_counts_and_access_changes(): void
    {
        $user = $this->createFullAccount('cacheperson');
        $url = $this->url($user->actor);
        $first = $this->get($url)->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $unchanged = $this->withHeader('If-None-Match', $etag)->get($url)->assertStatus(304);
        $this->assertSame('', $unchanged->getContent());
        $this->assertTrue($first->headers->hasCacheControlDirective('private'));
        $this->assertTrue($first->headers->hasCacheControlDirective('no-cache'));

        $user->profile->update(['bio' => 'Changed without updating Actor']);
        $changed = $this->withHeader('If-None-Match', $etag)->get($url)->assertOk();
        $this->assertNotSame($etag, $changed->headers->get('ETag'));
        $etag = $changed->headers->get('ETag');
        $follower = $this->remote('newfollower');
        Follow::query()->create(['follower_id' => $follower->id, 'following_id' => $user->actor->id, 'status' => 'accepted', 'requested_at' => now(), 'accepted_at' => now()]);
        $countChanged = $this->withHeader('If-None-Match', $etag)->get($url)->assertOk();
        $this->assertNotSame($etag, $countChanged->headers->get('ETag'));

        $user->actor->update(['status' => Actor::STATUS_DELETED]);
        $this->withHeader('If-None-Match', $countChanged->headers->get('ETag'))->get($url)->assertNotFound();
    }

    public function test_modified_since_does_not_validate_without_a_reliable_last_modified(): void
    {
        $actor = $this->remote('dates');
        $this->withHeader('If-Modified-Since', 'Wed, 01 Jan 2031 00:00:00 GMT')
            ->get($this->url($actor))->assertOk();
    }

    public function test_the_prototype_is_unavailable_outside_local_and_testing_even_with_a_cached_route(): void
    {
        $actor = $this->remote('production');
        $this->app->instance('env', 'production');
        $this->get($this->url($actor))->assertNotFound()->assertJsonPath('status', 404);
    }

    public function test_an_uninstalled_instance_returns_a_problem_instead_of_redirecting(): void
    {
        $this->simulateNotInstalled();
        $this->get('/rest/v1/persons/'.Str::uuid())->assertStatus(503)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 503);
    }

    public function test_rate_limit_errors_use_problem_details_and_keep_retry_after(): void
    {
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->get('/rest/v1/persons/not-a-uuid')->assertNotFound();
        }

        $this->get('/rest/v1/persons/not-a-uuid')->assertStatus(429)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader('Retry-After')
            ->assertJsonPath('status', 429);
    }
}
