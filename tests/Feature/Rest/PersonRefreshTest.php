<?php

namespace Tests\Feature\Rest;

use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\Concerns\UsesPassportKeys;
use Tests\TestCase;

class PersonRefreshTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase, UsesPassportKeys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        Passport::actingAs($this->createFullAccount('refreshviewer'), ['api'], 'rest');
        $this->withToken('test-token');
        Http::preventStrayRequests();
    }

    private function remote(): Actor
    {
        return $this->createRemoteActor('profileauthor', overrides: [
            'last_fetched_at' => now()->subDays(2), 'collections_fetched_at' => now()->subDays(2),
            'followers_count' => 10, 'following_count' => 3,
        ]);
    }

    public function test_remote_details_refresh_metadata_and_counts_with_existing_ttls_before_conditional_response(): void
    {
        $actor = $this->remote();
        $document = ['id' => $actor->uri, 'type' => 'Person',
            'publicKey' => ['id' => $actor->uri.'#main-key', 'owner' => $actor->uri, 'publicKeyPem' => $actor->key->public_key],
            'preferredUsername' => $actor->preferred_username, 'name' => 'Updated Name',
            'summary' => '<p>Updated biography</p>', 'published' => '2020-01-01T00:00:00Z',
            'inbox' => $actor->uri.'/inbox', 'outbox' => $actor->uri.'/outbox',
            'followers' => $actor->uri.'/followers', 'following' => $actor->uri.'/following'];
        Http::fake([
            $actor->uri => Http::sequence()->push($document)->push(array_merge($document, ['name' => 'Changed Again'])),
            $actor->uri.'/followers' => Http::response(['type' => 'OrderedCollection', 'totalItems' => 77]),
            $actor->uri.'/following' => Http::response(['type' => 'OrderedCollection', 'totalItems' => 5]),
        ]);
        $url = '/rest/v1/persons/'.$actor->id;
        $first = $this->get($url)->assertOk()->assertJsonPath('personProfile.personName', 'Updated Name')
            ->assertJsonPath('personProfile.personSummary', 'Updated biography')
            ->assertJsonPath('personFollowersCount', 77)->assertJsonPath('personFollowingCount', 5);
        $this->withHeader('If-None-Match', $first->headers->get('ETag'))->get($url)->assertStatus(304);
        Http::assertSentCount(3);
        $actor->refresh()->update(['last_fetched_at' => now()->subDays(2)]);
        $this->get($url)->assertOk()->assertJsonPath('personProfile.personName', 'Changed Again');
    }

    public function test_remote_failures_preserve_cached_details(): void
    {
        $actor = $this->remote();
        Http::fake(['*' => Http::response('', 503)]);
        $this->get('/rest/v1/persons/'.$actor->id)->assertOk()
            ->assertJsonPath('personProfile.personName', $actor->name)
            ->assertJsonPath('personFollowersCount', 10)->assertJsonPath('personFollowingCount', 3);
    }

    public function test_disappearance_during_refresh_is_rechecked_before_returning_person(): void
    {
        $actor = $this->remote();
        Http::fake(function () use ($actor) {
            $actor->update(['status' => Actor::STATUS_DELETED]);

            return Http::response('', 503);
        });
        $this->withHeader('If-None-Match', '"old"')->get('/rest/v1/persons/'.$actor->id)
            ->assertNotFound()->assertJsonPath('status', 404);
    }
}
