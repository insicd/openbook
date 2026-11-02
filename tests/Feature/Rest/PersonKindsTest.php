<?php

namespace Tests\Feature\Rest;

use App\Application\Services\CommunityRegistrar;
use App\Domain\Feeds\FeedSource;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\UsesPassportKeys;
use Tests\TestCase;

class PersonKindsTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase, UsesPassportKeys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        Passport::actingAs($this->createFullAccount('kindsviewer'), ['api'], 'rest');
        $this->withToken('test-token');
        Http::preventStrayRequests();
    }

    private function actor(string $type, bool $local = false): Actor
    {
        return Actor::create([
            'type' => $type, 'is_local' => $local,
            'preferred_username' => $type, 'domain' => 'example.org',
            'uri' => 'https://example.org/'.$type, 'name' => ucfirst($type),
            'status' => Actor::STATUS_ACTIVE, 'last_fetched_at' => now(),
            'collections_fetched_at' => now(), 'published_at' => now()->subYear(),
        ]);
    }

    public function test_all_four_remote_actor_types_use_the_person_representation(): void
    {
        foreach ([Actor::TYPE_PERSON, Actor::TYPE_APPLICATION, Actor::TYPE_FEED, Actor::TYPE_GROUP] as $type) {
            $actor = $this->actor($type);
            $this->get('/rest/v1/persons/'.$actor->id)->assertOk()
                ->assertJsonPath('personType', $type)->assertJsonPath('personId', $actor->id);
        }
    }

    public function test_local_groups_and_feeds_are_represented_without_a_user_profile(): void
    {
        $owner = $this->createFullAccount('groupowner');
        $group = app(CommunityRegistrar::class)->register($owner, ['slug' => 'publicgroup', 'name' => 'Public group']);
        $feed = $this->actor(Actor::TYPE_FEED, true);
        FeedSource::create(['actor_id' => $feed->id, 'feed_url' => 'https://example.org/rss',
            'feed_url_hash' => FeedSource::hashUrl('https://example.org/rss'), 'format' => 'rss']);
        foreach ([$group->actor, $feed] as $actor) {
            $this->get('/rest/v1/persons/'.$actor->id)->assertOk()
                ->assertJsonPath('personType', $actor->type)->assertJsonMissingPath('personRoles');
            foreach (['followers', 'following'] as $collection) {
                $this->get('/rest/v1/persons/'.$actor->id.'/'.$collection)->assertOk();
            }
        }
    }

    public function test_public_group_and_feed_references_are_kept_in_follow_lists(): void
    {
        $owner = $this->createFullAccount('followingowner');
        foreach ([Actor::TYPE_GROUP, Actor::TYPE_FEED] as $type) {
            $actor = $this->actor($type);
            Follow::create(['follower_id' => $owner->actor->id, 'following_id' => $actor->id,
                'status' => 'accepted', 'requested_at' => now(), 'accepted_at' => now()]);
        }
        $this->get('/rest/v1/persons/'.$owner->actor->id.'/following')->assertOk()->assertJsonCount(2);
    }

    public function test_private_community_identity_is_visible_but_membership_lists_are_protected(): void
    {
        $owner = $this->createFullAccount('privateowner');
        $community = app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'privategroup', 'name' => 'Private group', 'is_private' => false,
        ]);
        $base = '/rest/v1/persons/'.$community->actor->id;
        $etag = $this->get($base.'/followers')->assertOk()->headers->get('ETag');
        $community->update(['is_private' => true]);
        $this->withHeader('If-None-Match', $etag);
        $this->get($base)->assertOk()->assertJsonPath('personType', 'group');
        foreach (['followers', 'following'] as $collection) {
            $this->get($base.'/'.$collection)->assertForbidden();
        }
        $this->get('/rest/v1/persons/'.$owner->actor->id.'/following')->assertOk()->assertExactJson([]);
    }
}
