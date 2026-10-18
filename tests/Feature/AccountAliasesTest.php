<?php

namespace Tests\Feature;

use App\Application\Services\AccountAliasManager;
use App\Application\Services\DomainBlockManager;
use App\Application\Services\FollowManager;
use App\Domain\Accounts\User;
use App\Federation\Actors\Actor;
use App\Federation\Serialization\ActorSerializer;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class AccountAliasesTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake(['*' => Http::response('', 404)]);
    }

    public function test_guests_cannot_add_or_remove_aliases(): void
    {
        $this->post(route('settings.aliases.store'), ['alias' => 'old@example.org'])->assertRedirect(route('login'));
        $this->delete(route('settings.aliases.destroy'), ['alias' => 'https://example.org/users/old'])->assertRedirect(route('login'));
        Http::assertNothingSent();
    }

    public function test_a_handle_is_resolved_to_a_public_canonical_alias_without_moving_or_following(): void
    {
        $user = $this->createFullAccount('anna');
        $source = $this->createRemoteActor('oldanna');
        $other = $this->createFullAccount('other');
        Http::swap(new Factory);
        Http::fake([
            'https://remoto.example/.well-known/webfinger*' => Http::response([
                'subject' => 'acct:oldanna@remoto.example',
                'links' => [['rel' => 'self', 'type' => 'application/activity+json', 'href' => $source->uri]],
            ]),
        ]);

        $this->actingAs($user)->post(route('settings.aliases.store'), [
            'alias' => '@oldanna@remoto.example', 'user_id' => $other->id, 'actor_id' => $other->actor->id,
        ])->assertSessionHasNoErrors()->assertRedirect(route('settings.edit', ['tab' => 'migration']));

        $this->assertSame([$source->uri], $user->actor->fresh()->also_known_as);
        $this->assertEmpty($other->actor->fresh()->also_known_as);
        $this->assertNull($source->fresh()->moved_to_actor_id);
        $this->assertNull($user->actor->fresh()->moved_to_actor_id);
        $this->assertDatabaseCount('follows', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->get(route('settings.edit', ['tab' => 'migration']))->assertOk()->assertSee($source->uri)
            ->assertSee(__('openbook.settings.alias_remove_help'))
            ->assertSee('action="'.route('settings.aliases.destroy').'"', false);
        $this->get('/users/anna', ['Accept' => 'application/activity+json'])->assertOk()
            ->assertJsonPath('alsoKnownAs', [$source->uri]);
        Http::assertSentCount(1);
        Queue::assertNothingPushed();
    }

    public function test_alias_addition_and_removal_send_profile_updates_only_when_changed(): void
    {
        $user = $this->createFullAccount('anna');
        $source = $this->createRemoteActor('oldanna');
        $follower = $this->createRemoteActor('follower');
        app(FollowManager::class)->follow($follower, $user->actor);
        $this->actingAs($user)->post(route('settings.aliases.store'), ['alias' => $source->uri])
            ->assertSessionHasNoErrors();
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->signingActorId === $user->actor->id && $job->activity['type'] === 'Update'
            && $job->activity['object']['alsoKnownAs'] === [$source->uri]);
        Queue::assertPushed(DeliverActivityJob::class, 1);
        Queue::fake();

        $this->post(route('settings.aliases.store'), ['alias' => $source->uri])->assertSessionHasNoErrors();
        Queue::assertNothingPushed();
        $this->delete(route('settings.aliases.destroy'), ['alias' => $source->uri])->assertSessionHasNoErrors();
        $this->assertSame([], $user->actor->fresh()->also_known_as);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['object']['alsoKnownAs'] === []);
        Queue::assertPushed(DeliverActivityJob::class, 1);
        Http::assertNothingSent();
    }

    public function test_removal_is_private_idempotent_and_does_not_fetch_an_unavailable_source(): void
    {
        $anna = $this->createFullAccount('anna');
        $other = $this->createFullAccount('other');
        $alias = 'https://unavailable.example/users/old';
        $anna->actor->update(['also_known_as' => [$alias]]);
        $other->actor->update(['also_known_as' => [$alias]]);
        $this->actingAs($anna)->delete(route('settings.aliases.destroy'), ['alias' => $alias, 'user_id' => $other->id])->assertSessionHasNoErrors();
        $this->delete(route('settings.aliases.destroy'), ['alias' => $alias])->assertSessionHasNoErrors();
        $this->assertSame([], $anna->actor->fresh()->also_known_as);
        $this->assertSame([$alias], $other->actor->fresh()->also_known_as);
        $this->assertSame([], ActorSerializer::serialize($anna->actor->fresh())['alsoKnownAs']);
        Http::assertNothingSent();
    }

    public function test_aliases_are_merged_from_current_data_and_the_limit_does_not_prevent_removal(): void
    {
        $user = $this->createFullAccount('anna');
        $source = $this->createRemoteActor('oldanna');
        $staleUser = $user->fresh('actor');
        $existing = array_map(fn (int $i): string => 'https://old.example/users/'.$i, range(1, AccountAliasManager::MAX_ALIASES - 1));
        $user->actor->update(['also_known_as' => $existing]);
        $manager = app(AccountAliasManager::class);
        $manager->add($staleUser, $source->uri);
        $this->assertSame([...$existing, $source->uri], $user->actor->fresh()->also_known_as);
        $another = $this->createRemoteActor('another');
        $this->actingAs($user)->post(route('settings.aliases.store'), ['alias' => $another->uri])->assertSessionHasErrors('alias');
        $manager->remove($staleUser, $existing[0]);
        $manager->add($staleUser, $another->uri);
        $this->assertCount(AccountAliasManager::MAX_ALIASES, $user->actor->fresh()->also_known_as);
    }

    public static function invalidInputs(): array
    {
        return [[''], [['id' => 'https://remote.example/users/a']], [str_repeat('a', 2049)],
            ['javascript:alert(1)'], ['ftp://remote.example/users/old'], ['not an account'],
            ['https://127.0.0.1/users/old'], ['https://unavailable.example/users/old'],
            ['https://openbook.test/users/anna'], ['anna@openbook.test']];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_does_not_change_aliases(mixed $input): void
    {
        $user = $this->createFullAccount('anna');
        $this->actingAs($user)->post(route('settings.aliases.store'), ['alias' => $input])->assertSessionHasErrors('alias')
            ->assertRedirect(route('settings.edit', ['tab' => 'migration']));
        $this->assertEmpty($user->actor->fresh()->also_known_as);
        Queue::assertNothingPushed();
    }

    public static function unavailableSources(): array
    {
        return [['type', Actor::TYPE_GROUP], ['type', Actor::TYPE_FEED], ['status', Actor::STATUS_SUSPENDED],
            ['status', Actor::STATUS_BLOCKED], ['remote_suspended', true]];
    }

    #[DataProvider('unavailableSources')]
    public function test_ineligible_cached_accounts_cannot_be_added(string $field, mixed $value): void
    {
        $user = $this->createFullAccount('anna');
        $source = $this->createRemoteActor('oldanna', overrides: [$field => $value]);
        $this->actingAs($user)->post(route('settings.aliases.store'), ['alias' => $source->uri])->assertSessionHasErrors('alias');
        $this->assertEmpty($user->actor->fresh()->also_known_as);
        Http::assertNothingSent();
    }

    public function test_administratively_blocked_aliases_are_rejected_without_fetching(): void
    {
        $user = $this->createFullAccount('anna');
        app(DomainBlockManager::class)->block($this->createFullAccount('admin', ['is_admin' => true]), 'blocked.example');
        $this->actingAs($user)->post(route('settings.aliases.store'), ['alias' => 'old@blocked.example'])->assertSessionHasErrors('alias');
        $this->post(route('settings.aliases.store'), ['alias' => 'https://blocked.example/users/old'])->assertSessionHasErrors('alias');
        $this->assertEmpty($user->actor->fresh()->also_known_as);
        Http::assertNothingSent();
    }

    public function test_application_accounts_are_valid_sources(): void
    {
        $user = $this->createFullAccount('anna');
        $source = $this->createRemoteActor('bot', overrides: ['type' => Actor::TYPE_APPLICATION]);
        $this->actingAs($user)->post(route('settings.aliases.store'), ['alias' => $source->uri])->assertSessionHasNoErrors();
        $this->assertSame([$source->uri], $user->actor->fresh()->also_known_as);
    }

    public function test_inactive_accounts_cannot_change_aliases(): void
    {
        $user = $this->createFullAccount('anna');
        $user->update(['status' => User::STATUS_DISABLED]);
        $this->actingAs($user)->post(route('settings.aliases.store'), ['alias' => 'old@example.org'])->assertForbidden();
        $this->delete(route('settings.aliases.destroy'), ['alias' => 'https://example.org/users/old'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_moved_accounts_can_manage_aliases_normally(): void
    {
        $user = $this->createFullAccount('anna');
        $target = $this->createRemoteActor('destination');
        $user->actor->update(['moved_to_actor_id' => $target->id]);
        $this->actingAs($user)->post(route('settings.aliases.store'), ['alias' => $target->uri])->assertSessionHasNoErrors();
        $this->assertSame([$target->uri], $user->actor->fresh()->also_known_as);
        $this->delete(route('settings.aliases.destroy'), ['alias' => $target->uri])->assertSessionHasNoErrors();
        $this->assertSame([], $user->actor->fresh()->also_known_as);
        Http::assertNothingSent();
    }
}
