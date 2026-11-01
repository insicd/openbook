<?php

namespace Tests\Feature\Rest;

use App\Application\Services\CommunityRegistrar;
use App\Domain\Accounts\User;
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

class FollowRequestTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase, UsesPassportKeys;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        $this->viewer = $this->createFullAccount('requestviewer');
        $this->viewer->actor->update(['manually_approves_followers' => true]);
        Passport::actingAs($this->viewer, ['api'], 'rest');
        $this->withToken('test-token');
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function pending(Actor $sender, ?Actor $target = null, string $status = 'pending'): Follow
    {
        return Follow::create(['follower_id' => $sender->id, 'following_id' => ($target ?? $this->viewer->actor)->id,
            'status' => $status, 'requested_at' => now(), 'accepted_at' => $status === 'accepted' ? now() : null]);
    }

    private function url(Actor $sender): string
    {
        return '/rest/v1/me/follow-requests/'.$sender->id;
    }

    private function cachedPerson(int $i): Actor
    {
        return Actor::create(['type' => 'person', 'status' => 'active', 'is_local' => false,
            'preferred_username' => 'request'.$i, 'domain' => 'request.example', 'uri' => 'https://request.example/users/'.$i]);
    }

    public function test_list_is_private_paged_and_contains_only_the_current_users_available_pending_requesters(): void
    {
        $people = collect(range(1, 3))->map(fn ($i) => $this->cachedPerson($i));
        foreach ($people as $person) {
            $this->pending($person);
        }
        $other = $this->createFullAccount('requestother')->actor;
        $this->pending($this->cachedPerson(4), $other);
        $this->pending($this->cachedPerson(5), status: 'accepted');
        $deleted = $this->cachedPerson(6);
        $deleted->update(['status' => Actor::STATUS_DELETED]);
        $this->pending($deleted);
        $ids = [];
        $url = '/rest/v1/me/follow-requests?limit=1';
        for ($i = 0; $i < 3; $i++) {
            $response = $this->getJson($url)->assertOk()->assertJsonCount(1)->assertHeader('Vary', 'Authorization');
            $this->assertTrue($response->headers->hasCacheControlDirective('private'));
            $ids[] = $response->json('0.personId');
            $response->assertJsonMissingPath('0.email')->assertJsonMissingPath('0.followStatus');
            if ($i < 2) {
                preg_match('/<([^>]+)>; rel="next"/', $response->headers->get('Link', ''), $matches);
                $this->assertArrayHasKey(1, $matches);
                $url = $matches[1];
            } else {
                $response->assertHeaderMissing('Link');
            }
        }
        $this->assertSame($people->pluck('id')->sort()->values()->all(), $ids);
        $descending = $this->getJson('/rest/v1/me/follow-requests?direction=desc')->assertOk();
        $this->assertSame(array_reverse($ids), $descending->json('*.personId'));
        Http::assertNothingSent();
    }

    public function test_empty_default_limit_and_invalid_parameters(): void
    {
        $this->getJson('/rest/v1/me/follow-requests')->assertOk()->assertExactJson([]);
        foreach (range(1, 21) as $i) {
            $this->pending($this->cachedPerson($i));
        }
        $this->getJson('/rest/v1/me/follow-requests')->assertOk()->assertJsonCount(20)->assertHeader('Link');
        foreach (['limit=0', 'limit=101', 'after=invalid', 'direction=bad', 'sort=requestedAt', 'cursor=x', 'page=1'] as $query) {
            $this->getJson('/rest/v1/me/follow-requests?'.$query)->assertUnprocessable();
        }
    }

    public function test_acceptance_reuses_local_notifications_and_invalidates_the_list_cache(): void
    {
        $sender = $this->createFullAccount('requestsender');
        $follow = $this->pending($sender->actor);
        $first = $this->getJson('/rest/v1/me/follow-requests')->assertOk();
        $etag = $first->headers->get('ETag');
        $this->withHeader('If-None-Match', $etag)->getJson('/rest/v1/me/follow-requests')->assertStatus(304);
        $this->patchJson($this->url($sender->actor), ['followStatus' => 'accepted'])->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('accepted', $follow->fresh()->status);
        $this->getJson('/rest/v1/me/follow-requests')->assertOk()->assertExactJson([]);
        $this->assertDatabaseHas('notifications', ['recipient_id' => $sender->id, 'type' => Notification::TYPE_FOLLOW_ACCEPTED]);
        $this->patchJson($this->url($sender->actor), ['followStatus' => 'accepted'])->assertNotFound();
        $this->delete($this->url($sender->actor))->assertNotFound();
        $this->assertSame('accepted', $follow->fresh()->status);
    }

    public function test_rejection_does_not_unfollow_accepted_relations_and_notifies_local_sender(): void
    {
        $sender = $this->createFullAccount('rejectedrequest');
        $follow = $this->pending($sender->actor);
        $this->delete($this->url($sender->actor))->assertNoContent();
        $this->assertDatabaseMissing('follows', ['id' => $follow->id]);
        $this->assertDatabaseHas('notifications', ['recipient_id' => $sender->id, 'type' => Notification::TYPE_FOLLOW_REJECTED]);
        $this->delete($this->url($sender->actor))->assertNotFound();
        $accepted = $this->pending($sender->actor, status: 'accepted');
        $this->delete($this->url($sender->actor))->assertNotFound();
        $this->assertSame('accepted', $accepted->fresh()->status);
    }

    public function test_remote_accept_and_reject_use_signed_federation_delivery(): void
    {
        foreach (['Accept', 'Reject'] as $type) {
            $sender = $this->createRemoteActor('incoming'.$type);
            $follow = $this->pending($sender);
            if ($type === 'Accept') {
                $this->patchJson($this->url($sender), ['followStatus' => 'accepted'])->assertNoContent();
            } else {
                $this->delete($this->url($sender))->assertNoContent();
            }
            Queue::assertPushed(DeliverActivityJob::class, fn ($job) => $job->activity['type'] === $type
                && $job->signingActorId === $this->viewer->actor->id && $job->activity['object']['actor'] === $sender->uri);
        }
        Queue::assertPushed(DeliverActivityJob::class, 2);
        Http::assertNothingSent();
    }

    public function test_other_users_and_community_requests_cannot_be_managed_even_by_admin(): void
    {
        $this->viewer->update(['is_admin' => true]);
        $other = $this->createFullAccount('requestrecipient');
        $sender = $this->cachedPerson(1);
        $follow = $this->pending($sender, $other->actor);
        $this->patchJson($this->url($sender), ['followStatus' => 'accepted'])->assertNotFound();
        $this->delete($this->url($sender))->assertNotFound();
        $this->assertSame('pending', $follow->fresh()->status);
        $community = app(CommunityRegistrar::class)->register($this->viewer, ['slug' => 'requestcommunity', 'name' => 'Requests', 'is_private' => true]);
        $communitySender = $this->cachedPerson(2);
        $this->pending($communitySender, $community->actor);
        $this->patchJson($this->url($communitySender), ['followStatus' => 'accepted'])->assertNotFound();
        $this->delete($this->url($communitySender))->assertNotFound();
        $this->getJson('/rest/v1/me/follow-requests')->assertOk()->assertExactJson([]);
    }

    public function test_patch_payload_and_content_type_are_validated(): void
    {
        $sender = $this->cachedPerson(1);
        $follow = $this->pending($sender);
        foreach ([[], ['followStatus' => null], ['followStatus' => 'pending'], ['followStatus' => 'accepted', 'followingId' => 'other']] as $data) {
            $this->patchJson($this->url($sender), $data)->assertUnprocessable();
        }
        $this->call('PATCH', $this->url($sender), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-token'], content: '{broken')->assertStatus(400);
        $this->patch($this->url($sender), ['followStatus' => 'accepted'])->assertStatus(415);
        $this->assertSame('pending', $follow->fresh()->status);
    }

    public function test_unknown_deleted_and_missing_requests_return_404(): void
    {
        $deleted = $this->cachedPerson(1);
        $this->pending($deleted);
        $deleted->update(['status' => 'deleted']);
        foreach ([$deleted->id, 'invalid', (string) Str::uuid()] as $id) {
            $url = '/rest/v1/me/follow-requests/'.$id;
            $this->patchJson($url, ['followStatus' => 'accepted'])->assertNotFound();
            $this->delete($url)->assertNotFound();
        }
    }

    public function test_requests_remain_manageable_if_the_current_profile_was_opened(): void
    {
        $sender = $this->cachedPerson(1);
        $this->pending($sender);
        $this->viewer->actor->update(['manually_approves_followers' => false]);
        $this->getJson('/rest/v1/me/follow-requests')->assertOk()->assertJsonCount(1);
        $this->patchJson($this->url($sender), ['followStatus' => 'accepted'])->assertNoContent();
    }

    public function test_authentication_scope_and_account_checks_precede_cache_and_mutations(): void
    {
        $sender = $this->cachedPerson(1);
        $this->pending($sender);
        $etag = $this->getJson('/rest/v1/me/follow-requests')->headers->get('ETag');
        $this->withHeader('If-None-Match', $etag)->withoutHeader('Authorization');
        $this->getJson('/rest/v1/me/follow-requests')->assertUnauthorized();
        $this->patchJson($this->url($sender), ['followStatus' => 'accepted'])->assertUnauthorized();
        $this->delete($this->url($sender))->assertUnauthorized();
        $this->withToken('test-token');
        Passport::actingAs($this->viewer, [], 'rest');
        $this->getJson('/rest/v1/me/follow-requests')->assertForbidden();
        $this->patchJson($this->url($sender), ['followStatus' => 'accepted'])->assertForbidden();
        $this->delete($this->url($sender))->assertForbidden();
        Passport::actingAs($this->viewer, ['api'], 'rest');
        $this->viewer->update(['status' => User::STATUS_DISABLED]);
        $this->getJson('/rest/v1/me/follow-requests')->assertForbidden();
        $this->delete($this->url($sender))->assertForbidden();
    }
}
