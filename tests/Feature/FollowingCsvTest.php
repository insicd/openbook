<?php

namespace Tests\Feature;

use App\Domain\Accounts\User;
use App\Domain\Moderation\DomainBlock;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class FollowingCsvTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake(['*' => Http::response('', 404)]);
        $this->owner = $this->createFullAccount('anna');
    }

    private function follow(Actor $target, string $status = Follow::STATUS_ACCEPTED, ?User $user = null): Follow
    {
        return Follow::query()->create([
            'follower_id' => ($user ?? $this->owner)->actor->id, 'following_id' => $target->id,
            'status' => $status, 'requested_at' => now(),
            'accepted_at' => $status === Follow::STATUS_ACCEPTED ? now() : null,
        ]);
    }

    public function test_export_contains_only_the_owners_accepted_federated_follows(): void
    {
        $local = $this->createFullAccount('luca');
        $remote = $this->createRemoteActor('marco');
        $pending = $this->createRemoteActor('pending');
        $someoneElse = $this->createRemoteActor('private');
        $this->follow($local->actor);
        $this->follow($remote);
        $this->follow($pending, Follow::STATUS_PENDING);
        $this->follow($someoneElse, user: $local);

        $response = $this->actingAs($this->owner)->get(route('settings.following.export', ['user_id' => $local->id]));
        $response->assertOk()->assertDownload('following_accounts.csv')
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        $this->assertSame(['Account address'], $rows[0]);
        $addresses = array_column(array_slice($rows, 1), 0);
        $this->assertEqualsCanonicalizing([$local->actor->handle(), $remote->handle()], $addresses);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_export_rejects_guests_and_inactive_accounts(): void
    {
        $this->get(route('settings.following.export'))->assertRedirect(route('login'));
        $this->owner->update(['status' => User::STATUS_DISABLED]);
        $this->actingAs($this->owner)->get(route('settings.following.export'))->assertForbidden();
    }

    private function importCsv(string $contents): TestResponse
    {
        return $this->actingAs($this->owner)->post(route('settings.following.import'), [
            'csv' => UploadedFile::fake()->createWithContent('following_accounts.csv', $contents),
        ]);
    }

    public function test_import_immediately_follows_local_and_cached_remote_accounts_and_preserves_existing_requests(): void
    {
        $local = $this->createFullAccount('luca');
        $protected = $this->createFullAccount('protected');
        $protected->actor->update(['manually_approves_followers' => true]);
        $remote = $this->createRemoteActor('marco');
        $existing = $this->createRemoteActor('existing');
        $this->follow($existing, Follow::STATUS_PENDING);
        $before = Follow::query()->sole()->toArray();

        $response = $this->importCsv("Account address,Show boosts,Notify on new posts,Languages\r\n"
            .$local->actor->handle().",true,false,\r\n"
            .$protected->actor->handle().",true,false,\r\n"
            .$remote->handle().",false,true,it\r\n"
            .$existing->handle().",true,false,\r\n"
            .$local->actor->handle().",true,false,\r\n");
        $response->assertOk()->assertViewIs('settings.following_result')->assertViewHas('result', [
            'followed' => 1, 'pending' => 2, 'existing' => 1, 'failed' => [], 'duplicates' => 1,
        ]);
        $this->assertSame($before, Follow::query()->where('following_id', $existing->id)->sole()->toArray());
        $this->assertDatabaseHas('follows', ['follower_id' => $this->owner->actor->id, 'following_id' => $local->actor->id, 'status' => 'accepted']);
        $this->assertDatabaseHas('follows', ['follower_id' => $this->owner->actor->id, 'following_id' => $protected->actor->id, 'status' => 'pending']);
        Queue::assertPushed(DeliverActivityJob::class, 1);
        Queue::assertPushed(DeliverActivityJob::class, fn ($job) => $job->activity['type'] === 'Follow'
            && $job->activity['actor'] === $this->owner->actor->activityPubId() && $job->activity['object'] === $remote->uri);
        Http::assertNothingSent();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_import_resolves_unknown_accounts_and_queues_a_normal_follow(): void
    {
        $key = $this->createRemoteActor('key')->key->public_key;
        Http::swap(new Factory);
        Http::fake([
            'https://new.example/.well-known/webfinger*' => Http::response(['links' => [
                ['rel' => 'self', 'type' => 'application/activity+json', 'href' => 'https://new.example/users/new'],
            ]]),
            'https://new.example/users/new' => Http::response([
                '@context' => 'https://www.w3.org/ns/activitystreams', 'id' => 'https://new.example/users/new',
                'type' => 'Person', 'preferredUsername' => 'new', 'inbox' => 'https://new.example/users/new/inbox',
                'publicKey' => ['id' => 'https://new.example/users/new#key', 'owner' => 'https://new.example/users/new', 'publicKeyPem' => $key],
            ]),
        ]);
        $this->importCsv("Account address\nnew@new.example\n")->assertOk()->assertViewHas('result', fn ($r) => $r['pending'] === 1 && $r['failed'] === []);
        Http::assertSentCount(2);
        Queue::assertPushed(DeliverActivityJob::class, 1);
    }

    public function test_invalid_blocked_suspended_and_self_entries_do_not_prevent_other_follows(): void
    {
        $local = $this->createFullAccount('luca');
        $suspended = $this->createRemoteActor('suspended', overrides: ['remote_suspended' => true]);
        $blocked = $this->createRemoteActor('blocked', 'blocked.example');
        DomainBlock::query()->create(['domain' => 'blocked.example']);
        $response = $this->importCsv("Account address\nnot an address\n".$this->owner->actor->handle()."\n"
            .$suspended->handle()."\n".$blocked->handle()."\nmissing@unavailable.example\n".$local->actor->handle()."\n");
        $response->assertOk()->assertViewHas('result', fn ($r) => $r['followed'] === 1 && count($r['failed']) === 5);
        $this->assertDatabaseCount('follows', 1);
        Queue::assertNothingPushed();
    }

    public function test_bom_reordered_headers_quotes_and_legacy_headerless_files_are_supported(): void
    {
        $local = $this->createFullAccount('luca');
        $this->importCsv("\xEF\xBB\xBFShow boosts,Account address,Languages\r\ntrue,\"".$local->actor->handle()."\",\"en,it\"\r\n")
            ->assertOk()->assertViewHas('result', fn ($r) => $r['followed'] === 1);
        $this->importCsv("\n@".$local->actor->handle()."\n")
            ->assertOk()->assertViewHas('result', fn ($r) => $r['existing'] === 1);
        $this->assertDatabaseCount('follows', 1);
        Http::assertNothingSent();
    }

    public function test_invalid_files_are_rejected_before_any_follow_or_network_request(): void
    {
        foreach (['', "Wrong column,Extra\nfoo,bar\n", "Account address\n\xFF\n", "Account address\nfoo\0bar\n",
            "Account address\n".str_repeat("a@example.org\n", 10001)] as $contents) {
            $this->importCsv($contents)->assertSessionHasErrors('csv')->assertRedirect(route('settings.edit', ['tab' => 'import_export']));
        }
        $this->actingAs($this->owner)->post(route('settings.following.import'), ['csv' => UploadedFile::fake()->create('huge.csv', 1025)])
            ->assertSessionHasErrors('csv');
        $this->assertDatabaseCount('follows', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_import_is_private_and_requires_an_active_authenticated_owner(): void
    {
        $local = $this->createFullAccount('luca');
        $file = fn () => UploadedFile::fake()->createWithContent('following.csv', "Account address\n".$local->actor->handle());
        $this->post(route('settings.following.import'), ['csv' => $file()])->assertRedirect(route('login'));
        $other = $this->createFullAccount('other');
        $this->actingAs($this->owner)->post(route('settings.following.import'), ['csv' => $file(), 'user_id' => $other->id, 'actor_id' => $other->actor->id])
            ->assertOk();
        $this->assertDatabaseHas('follows', ['follower_id' => $this->owner->actor->id, 'following_id' => $local->actor->id]);
        $this->assertDatabaseMissing('follows', ['follower_id' => $other->actor->id]);
        $this->owner->update(['status' => User::STATUS_DISABLED]);
        $this->importCsv("Account address\n".$local->actor->handle())->assertForbidden();
    }
}
