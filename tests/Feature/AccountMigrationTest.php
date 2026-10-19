<?php

namespace Tests\Feature;

use App\Application\Services\AccountMigrationService;
use App\Application\Services\FollowManager;
use App\Application\Services\PostComposer;
use App\Domain\Accounts\User;
use App\Domain\Notifications\Notification;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Serialization\ActivitySerializer;
use App\Infrastructure\Security\LinkedData\LinkedDataSignature;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class AccountMigrationTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    private User $owner;

    private Actor $target;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->owner = $this->createFullAccount('anna');
        $this->target = $this->createRemoteActor('newanna', 'destination.example');
        $this->fakeDestination();
    }

    private function fakeDestination(array $overrides = [], int $status = 200): void
    {
        Http::swap(new Factory);
        Http::fake([
            'https://destination.example/.well-known/webfinger*' => Http::response([
                'subject' => 'acct:newanna@destination.example',
                'links' => [['rel' => 'self', 'type' => 'application/activity+json', 'href' => $this->target->uri]],
            ]),
            $this->target->uri => Http::response(array_replace([
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $this->target->uri,
                'type' => 'Person',
                'preferredUsername' => $this->target->preferred_username,
                'inbox' => $this->target->endpoints->inbox,
                'alsoKnownAs' => [$this->owner->actor->activityPubId()],
                'publicKey' => ['id' => $this->target->uri.'#main-key', 'owner' => $this->target->uri, 'publicKeyPem' => $this->target->key->public_key],
            ], $overrides), $status),
            '*' => Http::response('', 404),
        ]);
    }

    private function confirm(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->owner)->post(route('settings.migration.store'), array_replace([
            'destination' => $this->target->uri,
            'password' => 'Password123',
            'confirm_migration' => '1',
        ], $overrides));
    }

    private function follow(Actor $follower, string $status = Follow::STATUS_ACCEPTED): Follow
    {
        return Follow::query()->create([
            'follower_id' => $follower->id, 'following_id' => $this->owner->actor->id,
            'status' => $status, 'requested_at' => now(),
            'accepted_at' => $status === Follow::STATUS_ACCEPTED ? now() : null,
        ]);
    }

    public function test_preview_resolves_the_handle_and_requires_explicit_confirmation_without_starting_the_move(): void
    {
        $this->actingAs($this->owner)->post(route('settings.migration.preview'), ['destination' => '@newanna@destination.example'])
            ->assertOk()->assertSee($this->target->uri)
            ->assertSee('name="password"', false)->assertSee('name="confirm_migration"', false);
        $this->assertNull($this->owner->actor->fresh()->moved_to_actor_id);
        Http::assertSentCount(2);
        Queue::assertNothingPushed();
    }

    public function test_preview_errors_return_to_the_migration_tab_with_the_entered_destination(): void
    {
        $this->fakeDestination(['alsoKnownAs' => []]);
        $response = $this->actingAs($this->owner)->from(route('settings.edit', ['tab' => 'profile']))
            ->post(route('settings.migration.preview'), ['destination' => $this->target->uri]);
        $response->assertRedirect(route('settings.edit', ['tab' => 'migration']))->assertSessionHasErrors('destination');
        $this->get($response->headers->get('Location'))->assertOk()->assertViewHas('activeTab', 'migration')
            ->assertSee('value="'.$this->target->uri.'"', false)
            ->assertSee(__('openbook.settings.migration_invalid', ['app' => config('app.name')]));
        $this->assertNull($this->owner->actor->fresh()->moved_to_actor_id);
        Queue::assertNothingPushed();
    }

    public function test_confirmation_announces_one_move_per_remote_inbox_and_notifies_local_followers_without_moving_follows(): void
    {
        $luca = $this->createFullAccount('luca');
        $this->follow($luca->actor);
        $this->follow($this->createRemoteActor('remoteone', 'followers.example'));
        $this->follow($this->createRemoteActor('remotetwo', 'followers.example'));
        $this->follow($this->createRemoteActor('waiting', 'waiting.example'), Follow::STATUS_PENDING);
        $this->follow($this->createFullAccount('waitinglocal')->actor, Follow::STATUS_PENDING);
        $before = Follow::query()->orderBy('id')->get()->toArray();

        $this->confirm()->assertSessionHasNoErrors()->assertRedirect(route('settings.edit', ['tab' => 'migration']));
        $sourceUri = $this->owner->actor->activityPubId();
        $this->assertSame($this->target->id, $this->owner->actor->fresh()->moved_to_actor_id);
        $this->assertSame($before, Follow::query()->orderBy('id')->get()->toArray());
        $this->assertSame($luca->id, Notification::query()->sole()->recipient_id);
        $this->assertSame($this->target->profileUrl(), Notification::query()->sole()->targetUrl());
        Queue::assertPushed(DeliverActivityJob::class, 2);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->inboxUrl === 'https://followers.example/inbox' && $job->signingActorId === $this->owner->actor->id
            && $job->activity['type'] === 'Move' && $job->activity['actor'] === $sourceUri
            && $job->activity['object'] === $sourceUri && $job->activity['target'] === $this->target->uri
            && $job->activity['to'] === [$sourceUri.'/followers']
            && str_starts_with($job->activity['id'], $sourceUri.'/moves/')
            && isset($job->activity['signature']));
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Update' && $job->activity['object']['movedTo'] === $this->target->uri);
        $this->get('/users/anna', ['Accept' => 'application/activity+json'])->assertOk()->assertJsonPath('movedTo', $this->target->uri);
    }

    public function test_one_move_payload_is_shared_by_all_deliveries_and_preserved_in_the_queued_job(): void
    {
        $this->follow($this->createRemoteActor('first', 'first.example'));
        $this->follow($this->createRemoteActor('second', 'second.example'));
        $this->confirm()->assertSessionHasNoErrors();

        $moves = Queue::pushed(DeliverActivityJob::class)->filter(fn ($job) => $job->activity['type'] === 'Move')->values();
        $this->assertCount(2, $moves);
        $this->assertSame($moves[0]->activity, $moves[1]->activity);
        $restored = unserialize(serialize($moves[0]));
        $this->assertSame($moves[0]->activity, $restored->activity);
    }

    public function test_password_and_confirmation_are_required_before_any_fetch_or_changes(): void
    {
        $this->confirm(['password' => 'wrong'])->assertSessionHasErrors('password')->assertRedirect(route('settings.edit', ['tab' => 'migration']));
        $this->confirm(['confirm_migration' => null])->assertSessionHasErrors('confirm_migration');
        $this->assertNull($this->owner->actor->fresh()->moved_to_actor_id);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function invalidDestinations(): array
    {
        return ['missing alias' => [['alsoKnownAs' => []]],
            'different alias' => [['alsoKnownAs' => ['https://other.example/users/anna']]],
            'wrong identity' => [['id' => 'https://destination.example/users/other']],
            'community' => [['type' => 'Group']], 'suspended' => [['suspended' => true]]];
    }

    #[DataProvider('invalidDestinations')]
    public function test_an_unverified_destination_cannot_start_a_move(array $overrides): void
    {
        $this->fakeDestination($overrides);
        $this->from(route('settings.migration.preview'))->confirm()->assertSessionHasErrors('destination')->assertRedirect(route('settings.edit', ['tab' => 'migration']));
        $this->assertNull($this->owner->actor->fresh()->moved_to_actor_id);
        Queue::assertNothingPushed();
    }

    public function test_confirmation_rechecks_the_destination_and_does_not_trust_cached_aliases(): void
    {
        $this->actingAs($this->owner)->post(route('settings.migration.preview'), ['destination' => $this->target->uri])->assertOk();
        $this->fakeDestination(['alsoKnownAs' => []]);
        $this->confirm()->assertSessionHasErrors('destination');
        $this->fakeDestination(status: 503);
        $this->confirm()->assertSessionHasErrors('destination');
        Queue::assertNothingPushed();
    }

    public function test_repeat_confirmation_keeps_the_destination_and_does_not_send_another_announcement(): void
    {
        $this->follow($this->createRemoteActor('follower'));
        $this->confirm()->assertSessionHasNoErrors();
        Queue::fake();
        $this->confirm()->assertSessionHasNoErrors();
        $this->assertSame($this->target->id, $this->owner->actor->fresh()->moved_to_actor_id);
        Queue::assertNothingPushed();
    }

    public function test_removal_clears_the_notice_and_sends_a_profile_update_without_changing_follows(): void
    {
        $this->follow($this->createRemoteActor('follower'));
        $this->confirm()->assertSessionHasNoErrors();
        $before = Follow::query()->get()->toArray();
        $this->get('/@anna')->assertOk()->assertSee(__('openbook.settings.migration_notice'));
        $this->get(route('settings.edit', ['tab' => 'migration']))->assertOk()->assertSee(__('openbook.settings.migration_remove'));
        Queue::fake();
        $this->delete(route('settings.migration.destroy'))->assertRedirect(route('settings.edit', ['tab' => 'migration']));
        $this->assertNull($this->owner->actor->fresh()->moved_to_actor_id);
        $this->assertSame($before, Follow::query()->get()->toArray());
        $this->get('/users/anna', ['Accept' => 'application/activity+json'])->assertJsonPath('movedTo', null);
        $this->get('/@anna')->assertOk()->assertDontSee(__('openbook.settings.migration_notice'));
        Queue::assertPushed(DeliverActivityJob::class, 1);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Update' && array_key_exists('movedTo', $job->activity['object'])
            && $job->activity['object']['movedTo'] === null);
        Queue::fake();
        $this->delete(route('settings.migration.destroy'))->assertRedirect(route('settings.edit', ['tab' => 'migration']));
        Queue::assertNothingPushed();
    }

    public function test_a_new_transfer_after_removal_has_a_new_move_id_and_notifies_again(): void
    {
        $this->follow($this->createFullAccount('luca')->actor);
        $this->follow($this->createRemoteActor('follower'));
        $this->confirm()->assertSessionHasNoErrors();
        $first = Queue::pushed(DeliverActivityJob::class)->first(fn ($job) => $job->activity['type'] === 'Move')->activity['id'];
        $this->delete(route('settings.migration.destroy'))->assertRedirect(route('settings.edit', ['tab' => 'migration']));
        $this->confirm()->assertSessionHasNoErrors();
        $moves = Queue::pushed(DeliverActivityJob::class)->filter(fn ($job) => $job->activity['type'] === 'Move')->values();
        $this->assertCount(2, $moves);
        $this->assertNotSame($first, $moves[1]->activity['id']);
        $this->assertSame($this->target->id, $this->owner->actor->fresh()->moved_to_actor_id);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_the_original_account_can_still_publish_follow_and_receive_follows(): void
    {
        $this->confirm()->assertSessionHasNoErrors();
        $post = app(PostComposer::class)->compose($this->owner->actor->fresh(), ['body' => 'My new address is on my profile.', 'visibility' => 'public']);
        $this->assertSame($this->owner->actor->id, $post->actor_id);
        $newFollower = $this->createFullAccount('newfollower');
        $follow = app(FollowManager::class)->follow($newFollower->actor, $this->owner->actor->fresh());
        $this->assertSame(Follow::STATUS_ACCEPTED, $follow->status);
        app(FollowManager::class)->follow($this->owner->actor->fresh(), $newFollower->actor);
        $this->get('/@anna')->assertOk()->assertSee('My new address is on my profile.')
            ->assertSee(__('openbook.settings.migration_notice'));
    }

    public function test_notification_failure_rolls_back_the_migration_and_the_profile_marker(): void
    {
        $this->follow($this->createFullAccount('luca')->actor);
        Notification::creating(fn () => throw new RuntimeException('notification failure'));
        try {
            app(AccountMigrationService::class)->start($this->owner, $this->target->uri);
            $this->fail('Expected a notification failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('notification failure', $e->getMessage());
        } finally {
            Notification::flushEventListeners();
        }
        $this->assertNull($this->owner->actor->fresh()->moved_to_actor_id);
        Queue::assertNothingPushed();
    }

    public function test_profile_update_signature_covers_the_move_destination_and_removal(): void
    {
        $this->confirm()->assertSessionHasNoErrors();
        $actor = $this->owner->actor->fresh();
        $signatures = app(LinkedDataSignature::class);
        $move = $signatures->sign(ActivitySerializer::move($actor, $this->target), $actor);
        $this->assertNotNull($signatures->verifyActor($move, $actor->activityPubId()));
        $move['target'] = 'https://attacker.example/users/anna';
        $this->assertNull($signatures->verifyActor($move, $actor->activityPubId()));
        $signed = $signatures->sign(ActivitySerializer::updateActor($actor), $actor);
        $this->assertNotNull($signatures->verifyActor($signed, $actor->activityPubId()));
        $signed['object']['movedTo'] = 'https://attacker.example/users/anna';
        $this->assertNull($signatures->verifyActor($signed, $actor->activityPubId()));
        $signed['object']['movedTo'] = null;
        $this->assertNull($signatures->verifyActor($signed, $actor->activityPubId()));
    }

    public function test_only_the_authenticated_owner_can_change_their_migration(): void
    {
        $this->confirm()->assertSessionHasNoErrors();
        $other = $this->createFullAccount('other');
        $this->actingAs($other)->delete(route('settings.migration.destroy'), ['user_id' => $this->owner->id])->assertRedirect(route('settings.edit', ['tab' => 'migration']));
        $this->assertSame($this->target->id, $this->owner->actor->fresh()->moved_to_actor_id);
        $this->post(route('settings.migration.store'), [
            'user_id' => $this->owner->id, 'destination' => $this->target->uri,
            'password' => 'Password123', 'confirm_migration' => 1,
        ])->assertSessionHasErrors('destination');
        $this->assertNull($other->actor->fresh()->moved_to_actor_id);
    }

    public function test_guests_and_disabled_users_cannot_start_or_remove_a_migration(): void
    {
        $this->post(route('settings.migration.preview'), ['destination' => $this->target->uri])->assertRedirect(route('login'));
        $this->delete(route('settings.migration.destroy'))->assertRedirect(route('login'));
        $this->owner->update(['status' => User::STATUS_DISABLED]);
        $this->confirm()->assertForbidden();
        $this->delete(route('settings.migration.destroy'))->assertForbidden();
        Http::assertNothingSent();
    }
}
