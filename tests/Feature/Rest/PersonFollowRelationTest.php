<?php

namespace Tests\Feature\Rest;

use App\Application\Services\CommunityRegistrar;
use App\Application\Services\FollowManager;
use App\Domain\Accounts\User;
use App\Domain\Feeds\FeedSource;
use App\Domain\Notifications\Notification;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\Concerns\UsesPassportKeys;
use Tests\TestCase;

class PersonFollowRelationTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase, UsesPassportKeys;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        $this->viewer = $this->createFullAccount('followviewer');
        Passport::actingAs($this->viewer, ['api'], 'rest');
        $this->withToken('test-token');
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function url(Actor $actor): string
    {
        return '/rest/v1/persons/'.$actor->id.'/follow';
    }

    private function assertState(Actor $target, string $status): void
    {
        $this->getJson($this->url($target))->assertOk()->assertExactJson(['followStatus' => $status]);
    }

    public function test_open_local_follow_and_removal_are_idempotent(): void
    {
        $target = $this->createFullAccount('openfollow');
        $this->assertState($target->actor, 'none');
        $created = $this->withHeader('Origin', 'https://client.example')->put($this->url($target->actor))
            ->assertCreated()->assertExactJson(['followStatus' => 'accepted'])
            ->assertHeader('Location', url($this->url($target->actor)))->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('Location', $created->headers->get('Access-Control-Expose-Headers'));
        $this->put($this->url($target->actor))->assertOk()->assertExactJson(['followStatus' => 'accepted']);
        $this->assertState($target->actor, 'accepted');
        $this->assertSame(1, Follow::query()->where('following_id', $target->actor->id)->count());
        $this->assertSame(1, Notification::query()->where('recipient_id', $target->id)->where('type', Notification::TYPE_NEW_FOLLOWER)->count());
        $this->delete($this->url($target->actor))->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');
        $this->delete($this->url($target->actor))->assertNoContent();
        $this->assertState($target->actor, 'none');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_protected_local_follow_changes_after_acceptance(): void
    {
        $target = $this->createFullAccount('protectedfollow')->actor;
        $target->update(['manually_approves_followers' => true]);
        $this->put($this->url($target))->assertCreated()->assertExactJson(['followStatus' => 'pending']);
        $this->put($this->url($target))->assertOk()->assertExactJson(['followStatus' => 'pending']);
        $pending = $this->getJson($this->url($target))->assertOk()->assertExactJson(['followStatus' => 'pending']);
        app(FollowManager::class)->accept($target, $this->viewer->actor);
        $this->withHeader('If-None-Match', $pending->headers->get('ETag'))->getJson($this->url($target))
            ->assertOk()->assertExactJson(['followStatus' => 'accepted']);
    }

    public function test_rejection_and_withdrawal_return_none_and_allow_a_new_request(): void
    {
        $target = $this->createFullAccount('rejectfollow')->actor;
        $target->update(['manually_approves_followers' => true]);
        $this->put($this->url($target))->assertCreated();
        app(FollowManager::class)->reject($target, $this->viewer->actor);
        $this->assertState($target, 'none');
        $this->put($this->url($target))->assertCreated()->assertExactJson(['followStatus' => 'pending']);
        $this->delete($this->url($target))->assertNoContent();
        $this->assertState($target, 'none');
        $this->delete($this->url($target))->assertNoContent();
    }

    public function test_remote_follow_retries_pending_requests_and_reuses_federation(): void
    {
        $target = $this->createRemoteActor('remotefollow', overrides: ['manually_approves_followers' => false]);
        $this->put($this->url($target))->assertCreated()->assertExactJson(['followStatus' => 'pending']);
        $this->put($this->url($target))->assertOk()->assertExactJson(['followStatus' => 'pending']);
        $this->assertSame(1, Follow::query()->count());
        Queue::assertPushed(DeliverActivityJob::class, 2);
        $follow = Follow::query()->sole();
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job) => $job->activity['type'] === 'Follow'
            && $job->activity['object'] === $target->uri && $job->signingActorId === $this->viewer->actor->id);
        $this->assertState($target, 'pending');
        Queue::assertPushed(DeliverActivityJob::class, 2);
        app(FollowManager::class)->accept($target, $this->viewer->actor);
        $this->put($this->url($target))->assertOk()->assertExactJson(['followStatus' => 'accepted']);
        Queue::assertPushed(DeliverActivityJob::class, 2);
        $this->delete($this->url($target))->assertNoContent();
        $this->delete($this->url($target))->assertNoContent();
        Queue::assertPushed(DeliverActivityJob::class, 3);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job) => $job->activity['type'] === 'Undo'
            && $job->activity['object']['type'] === 'Follow' && $job->activity['object']['object'] === $target->uri);
        $this->assertDatabaseMissing('follows', ['id' => $follow->id]);
        Http::assertNothingSent();
    }

    public function test_pending_remote_follow_can_be_withdrawn(): void
    {
        $target = $this->createRemoteActor('withdrawfollow');
        $this->put($this->url($target))->assertCreated();
        $this->delete($this->url($target))->assertNoContent();
        $this->assertState($target, 'none');
        Queue::assertPushed(DeliverActivityJob::class, 2);
    }

    public function test_get_is_conditional_private_and_never_retries_remote_requests(): void
    {
        $target = $this->createRemoteActor('cachefollow');
        $first = $this->getJson($this->url($target))->assertOk()->assertHeader('Vary', 'Authorization');
        $this->assertTrue($first->headers->hasCacheControlDirective('private'));
        $this->withHeader('If-None-Match', $first->headers->get('ETag'))->getJson($this->url($target))->assertStatus(304);
        $this->put($this->url($target))->assertCreated()->assertExactJson(['followStatus' => 'pending']);
        $second = $this->getJson($this->url($target))->assertOk()->assertExactJson(['followStatus' => 'pending']);
        Queue::fake();
        $this->withHeader('If-None-Match', $second->headers->get('ETag'))->getJson($this->url($target))->assertStatus(304);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_self_follow_is_rejected_without_hiding_the_readable_none_state(): void
    {
        $this->assertState($this->viewer->actor, 'none');
        $this->put($this->url($this->viewer->actor))->assertUnprocessable()->assertHeader('Content-Type', 'application/problem+json');
        $this->delete($this->url($this->viewer->actor))->assertNoContent();
        $this->assertSame(0, Follow::query()->count());
    }

    public function test_suspended_remote_cannot_receive_new_follows_but_can_be_unfollowed(): void
    {
        $target = $this->createRemoteActor('suspendfollow');
        $this->put($this->url($target))->assertCreated();
        $target->update(['remote_suspended' => true]);
        $this->assertState($target, 'pending');
        $this->put($this->url($target))->assertForbidden();
        $this->delete($this->url($target))->assertNoContent();
        $this->assertState($target, 'none');
        $this->put($this->url($target))->assertForbidden();
        $this->assertSame(0, Follow::query()->count());
    }

    public function test_unavailable_identities_return_404_even_if_a_follow_exists(): void
    {
        $local = $this->createFullAccount('unavailablefollow')->actor;
        $this->put($this->url($local))->assertCreated();
        $local->update(['status' => Actor::STATUS_SUSPENDED]);
        $deleted = $this->createRemoteActor('deletedfollow', overrides: ['status' => Actor::STATUS_DELETED]);
        foreach ([$local->id, $deleted->id, 'invalid', (string) Str::uuid()] as $id) {
            $url = '/rest/v1/persons/'.$id.'/follow';
            $this->getJson($url)->assertNotFound();
            $this->put($url)->assertNotFound();
            $this->delete($url)->assertNotFound();
        }
        $this->assertTrue(app(FollowManager::class)->isFollowing($this->viewer->actor, $local));
    }

    public function test_domain_restrictions_are_not_reported_as_internal_errors(): void
    {
        $target = $this->createFullAccount('inactivefollow');
        $target->update(['status' => User::STATUS_DISABLED]);
        $this->put($this->url($target->actor))->assertUnprocessable()->assertJsonPath('status', 422);
        $remote = $this->createRemoteActor('blockedfollow', overrides: ['status' => Actor::STATUS_BLOCKED]);
        $this->put($this->url($remote))->assertUnprocessable();
        $this->assertSame(0, Follow::query()->count());
    }

    public function test_application_group_and_feed_types_remain_followable(): void
    {
        foreach ([Actor::TYPE_APPLICATION, Actor::TYPE_GROUP] as $type) {
            $target = $this->createRemoteActor('typedfollow'.$type, overrides: ['type' => $type]);
            $this->put($this->url($target))->assertCreated()->assertExactJson(['followStatus' => 'pending']);
            $this->delete($this->url($target))->assertNoContent();
        }
        $feed = Actor::create(['type' => Actor::TYPE_FEED, 'is_local' => true, 'status' => Actor::STATUS_ACTIVE,
            'preferred_username' => 'typedfeed', 'domain' => 'example.org', 'uri' => 'https://example.org/feeds/typedfeed']);
        FeedSource::create(['actor_id' => $feed->id, 'feed_url' => 'https://feed.example/rss',
            'feed_url_hash' => FeedSource::hashUrl('https://feed.example/rss'), 'format' => FeedSource::FORMAT_RSS]);
        Queue::fake();
        $this->put($this->url($feed))->assertCreated()->assertExactJson(['followStatus' => 'accepted']);
        $this->delete($this->url($feed))->assertNoContent();
        Queue::assertNothingPushed();
    }

    public function test_local_community_membership_policies_counts_and_pending_state_are_reused(): void
    {
        $owner = $this->createFullAccount('communityfollowowner');
        foreach ([false, true] as $private) {
            $community = app(CommunityRegistrar::class)->register($owner,
                ['slug' => $private ? 'privatefollow' : 'publicfollow', 'name' => 'Follow community', 'is_private' => $private]);
            $target = $community->actor;
            $initial = $community->fresh()->members_count;
            $this->assertState($target, 'none');
            $this->put($this->url($target))->assertCreated()->assertExactJson(['followStatus' => $private ? 'pending' : 'accepted']);
            $this->put($this->url($target))->assertOk();
            $this->assertSame($initial + ($private ? 0 : 1), $community->fresh()->members_count);
            $this->delete($this->url($target))->assertNoContent();
            $this->delete($this->url($target))->assertNoContent();
            $this->assertSame($initial, $community->fresh()->members_count);
        }
    }

    public function test_community_owner_cannot_bypass_membership_rules_via_the_person_path(): void
    {
        $community = app(CommunityRegistrar::class)->register($this->viewer,
            ['slug' => 'ownerfollow', 'name' => 'Owner community', 'is_private' => true]);
        $target = $community->actor;
        $initial = $community->fresh()->members_count;
        $this->assertState($target, 'accepted');
        $this->put($this->url($target))->assertForbidden();
        $this->delete($this->url($target))->assertForbidden();
        $this->assertSame($initial, $community->fresh()->members_count);
        $this->assertState($target, 'accepted');
    }

    public function test_existing_pending_local_request_can_be_healed_when_profile_becomes_open(): void
    {
        $target = $this->createFullAccount('healfollow')->actor;
        $target->update(['manually_approves_followers' => true]);
        $this->put($this->url($target))->assertCreated();
        $target->update(['manually_approves_followers' => false]);
        $this->put($this->url($target))->assertOk()->assertExactJson(['followStatus' => 'accepted']);
    }

    public function test_state_direction_and_mutations_are_bound_to_the_bearer(): void
    {
        $target = $this->createFullAccount('directionfollow')->actor;
        app(FollowManager::class)->follow($target, $this->viewer->actor);
        $this->assertState($target, 'none');
        $another = $this->createFullAccount('anotherfollow');
        $this->putJson($this->url($target), ['followerId' => $another->actor->id, 'followStatus' => 'accepted'])
            ->assertCreated()->assertExactJson(['followStatus' => 'accepted']);
        $etag = $this->getJson($this->url($target))->headers->get('ETag');
        Passport::actingAs($another, ['api'], 'rest');
        $this->withHeader('If-None-Match', $etag)->getJson($this->url($target))->assertOk()->assertExactJson(['followStatus' => 'none']);
        $this->delete($this->url($target))->assertNoContent();
        $this->assertTrue(app(FollowManager::class)->isFollowing($this->viewer->actor, $target));
        $this->assertTrue(app(FollowManager::class)->isFollowing($target, $this->viewer->actor));
    }

    public function test_authentication_scope_account_and_environment_are_checked_before_cache(): void
    {
        $target = $this->createFullAccount('authfollow')->actor;
        $etag = $this->getJson($this->url($target))->headers->get('ETag');
        $this->withHeader('If-None-Match', $etag)->withoutHeader('Authorization');
        $this->getJson($this->url($target))->assertUnauthorized();
        $this->put($this->url($target))->assertUnauthorized();
        $this->delete($this->url($target))->assertUnauthorized();
        $this->withToken('test-token');
        Passport::actingAs($this->viewer, [], 'rest');
        $this->getJson($this->url($target))->assertForbidden();
        $this->put($this->url($target))->assertForbidden();
        $this->delete($this->url($target))->assertForbidden();
        Passport::actingAs($this->viewer, ['api'], 'rest');
        $this->viewer->actor->update(['status' => Actor::STATUS_BLOCKED]);
        $this->getJson($this->url($target))->assertForbidden();
        $this->put($this->url($target))->assertForbidden();
        $this->delete($this->url($target))->assertForbidden();
        $this->viewer->actor->update(['status' => Actor::STATUS_ACTIVE]);
        $this->app->detectEnvironment(fn () => 'production');
        $this->getJson($this->url($target))->assertNotFound();
        $this->put($this->url($target))->assertNotFound();
        $this->delete($this->url($target))->assertNotFound();
    }
}
