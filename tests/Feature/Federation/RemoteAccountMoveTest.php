<?php

namespace Tests\Feature\Federation;

use App\Application\Services\DomainBlockManager;
use App\Domain\Accounts\User;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Actors\RemoteActorResolver;
use App\Federation\Inbox\InboxActivityProcessor;
use App\Federation\Inbox\InboxItem;
use App\Infrastructure\Security\HttpSignatureSigner;
use App\Infrastructure\Security\RsaKeyPairGenerator;
use App\Jobs\Federation\ProcessInboxActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class RemoteAccountMoveTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    private Actor $source;

    private Actor $target;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->source = $this->createRemoteActor('pippo', 'server-a.example');
        $this->target = $this->createRemoteActor('pluto', 'server-b.example');
        $this->fakeTarget();
    }

    private function document(array $overrides = []): array
    {
        return array_replace([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $this->target->uri,
            'type' => 'Person',
            'preferredUsername' => 'pluto',
            'inbox' => $this->target->uri.'/inbox',
            'alsoKnownAs' => [$this->source->uri],
            'publicKey' => [
                'id' => $this->target->uri.'#main-key',
                'owner' => $this->target->uri,
                'publicKeyPem' => $this->target->key->public_key,
            ],
        ], $overrides);
    }

    private function fakeTarget(array $overrides = [], int $status = 200): void
    {
        Http::swap(new Factory);
        Http::fake([
            $this->target->uri => Http::response($this->document($overrides), $status),
            '*' => Http::response('', 404),
        ]);
    }

    private function activity(array $overrides = []): array
    {
        return array_replace([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $this->source->uri.'/moves/'.uniqid(),
            'type' => 'Move',
            'actor' => $this->source->uri,
            'object' => $this->source->uri,
            'target' => $this->target->uri,
            'to' => [$this->source->uri.'/followers'],
        ], $overrides);
    }

    private function process(array $overrides = []): string
    {
        $activity = $this->activity($overrides);
        $item = InboxItem::query()->create([
            'is_shared' => true,
            'remote_activity_uri' => $activity['id'],
            'activity_type' => $activity['type'],
            'actor_uri' => $this->source->uri,
            'payload' => json_encode($activity, JSON_THROW_ON_ERROR),
            'signature_valid' => true,
            'status' => InboxItem::STATUS_PENDING,
            'received_at' => now(),
        ]);

        return app(InboxActivityProcessor::class)->process($item);
    }

    private function follow(Actor $follower, ?Actor $target = null, string $status = Follow::STATUS_ACCEPTED): Follow
    {
        return Follow::query()->create([
            'follower_id' => $follower->id,
            'following_id' => ($target ?? $this->source)->id,
            'status' => $status,
            'requested_at' => now(),
            'accepted_at' => $status === Follow::STATUS_ACCEPTED ? now() : null,
            'auto_announce' => true,
        ]);
    }

    public function test_move_notifies_local_followers_without_changing_any_follow_or_post(): void
    {
        $anna = $this->createFullAccount('anna');
        $marco = $this->createFullAccount('marco');
        $pending = $this->createFullAccount('pending');
        $inactive = $this->createFullAccount('inactive');
        $inactive->update(['status' => User::STATUS_DISABLED]);
        $this->follow($anna->actor);
        $this->follow($marco->actor);
        $this->follow($anna->actor, $this->target, Follow::STATUS_PENDING);
        $this->follow($pending->actor, status: Follow::STATUS_PENDING);
        $this->follow($inactive->actor);
        $this->follow($this->createRemoteActor('remote'));
        $post = Post::query()->create([
            'actor_id' => $this->source->id, 'body' => 'Old post',
            'visibility' => 'public', 'published_at' => now(),
        ]);
        $follows = Follow::query()->orderBy('id')->get()->toArray();
        $postBefore = $post->fresh()->toArray();

        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process());
        $this->assertSame($this->target->id, $this->source->fresh()->moved_to_actor_id);
        $this->assertSame($follows, Follow::query()->orderBy('id')->get()->toArray());
        $this->assertSame($postBefore, $post->fresh()->toArray());
        $this->assertEqualsCanonicalizing([$anna->id, $marco->id], Notification::query()->pluck('recipient_id')->all());
        $this->assertSame(1, $anna->fresh()->notifications_revision);
        $notification = Notification::query()->where('recipient_id', $anna->id)->firstOrFail();
        $this->assertSame($this->target->profileUrl(), $notification->targetUrl());
        $this->assertSame($this->target->profileUrl(), $notification->actorProfileUrl());
        $this->assertStringContainsString('@pippo@server-a.example', $notification->message('it'));
        $this->assertStringContainsString('@pluto@server-b.example', $notification->message('en'));
        Http::assertSentCount(1);
        Queue::assertNothingPushed();

        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process());
        $this->assertDatabaseCount('notifications', 2);
    }

    private function sourceDocument(array $overrides = []): array
    {
        return $this->document(array_replace([
            'id' => $this->source->uri,
            'preferredUsername' => 'pippo',
            'movedTo' => $this->target->uri,
            'publicKey' => [
                'id' => $this->source->uri.'#main-key',
                'owner' => $this->source->uri,
                'publicKeyPem' => $this->source->key->public_key,
            ],
        ], $overrides));
    }

    public function test_visiting_old_profile_links_cached_destination_without_notifying_and_later_move_still_notifies(): void
    {
        $anna = $this->createFullAccount('anna');
        $this->follow($anna->actor);
        $this->source->update(['last_fetched_at' => now()->subDays(2)]);
        Http::swap(new Factory);
        Http::fake([
            $this->source->uri => Http::response($this->sourceDocument()),
            '*' => Http::response('', 404),
        ]);

        $this->actingAs($anna)->get($this->source->profileUrl())
            ->assertOk()->assertSee(__('openbook.actors.moved_notice'))
            ->assertSee('href="'.$this->target->profileUrl().'"', false);
        $this->assertSame($this->target->id, $this->source->fresh()->moved_to_actor_id);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('follows', 1);
        Http::assertNotSent(fn ($request): bool => $request->url() === $this->target->uri);
        Queue::assertNothingPushed();

        $this->fakeTarget();
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process());
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process());
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_unknown_destination_is_fetched_once_without_following_its_moved_to_chain(): void
    {
        $targetDocument = $this->document(['movedTo' => 'https://third.example/users/newer']);
        $targetUri = $this->target->uri;
        $this->target->delete();
        Http::swap(new Factory);
        Http::fake([
            $targetUri => Http::response($targetDocument),
            '*' => Http::response('', 404),
        ]);
        app(RemoteActorResolver::class)->applyRemoteDocument($this->sourceDocument(), $this->source->uri);

        $target = Actor::query()->where('uri', $targetUri)->sole();
        $this->assertSame($target->id, $this->source->fresh()->moved_to_actor_id);
        $this->assertNull($target->moved_to_actor_id);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('follows', 0);
        Http::assertSentCount(1);
        Queue::assertNothingPushed();
    }

    public function test_invalid_self_local_or_blocked_moved_to_does_not_fetch_or_link(): void
    {
        app(DomainBlockManager::class)->block($this->createFullAccount('admin', ['is_admin' => true]), 'blocked.example');
        foreach ([
            'javascript:alert(1)', 'ftp://remote.example/users/other',
            $this->source->uri, url('/users/local'),
            'https://blocked.example/users/other', ['id' => []], null,
        ] as $uri) {
            app(RemoteActorResolver::class)->applyRemoteDocument($this->sourceDocument(['movedTo' => $uri]), $this->source->uri);
            $this->assertNull($this->source->fresh()->moved_to_actor_id);
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_failed_destination_fetch_preserves_profile_and_sends_no_notification(): void
    {
        $this->target->delete();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('', 503)]);
        $actor = app(RemoteActorResolver::class)->applyRemoteDocument($this->sourceDocument(['name' => 'Updated Pippo']), $this->source->uri);
        $this->assertSame('Updated Pippo', $actor->name);
        $this->assertNull($actor->moved_to_actor_id);
        $this->assertDatabaseCount('notifications', 0);
    }

    public static function invalidDocuments(): array
    {
        return [
            'alias missing' => [['alsoKnownAs' => []]],
            'alias wrong' => [['alsoKnownAs' => ['https://other.example/users/pippo']]],
            'alias malformed' => [['alsoKnownAs' => ['id' => 'https://server-a.example/users/pippo']]],
            'id wrong' => [['id' => 'https://server-b.example/users/other']],
            'group' => [['type' => 'Group']],
            'suspended' => [['suspended' => true]],
        ];
    }

    #[DataProvider('invalidDocuments')]
    public function test_invalid_destination_does_not_notify_or_mark_the_source(array $overrides): void
    {
        $this->follow($this->createFullAccount('anna')->actor);
        $this->fakeTarget($overrides);
        $this->assertSame(InboxItem::STATUS_IGNORED, $this->process());
        $this->assertNull($this->source->fresh()->moved_to_actor_id);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_failed_fetch_does_not_trust_cached_aliases(): void
    {
        $this->target->update(['also_known_as' => [$this->source->uri]]);
        $this->fakeTarget(status: 503);
        $this->assertSame(InboxItem::STATUS_IGNORED, $this->process());
        $this->assertNull($this->source->fresh()->moved_to_actor_id);
    }

    public function test_wrong_sender_object_self_move_and_local_target_are_ignored(): void
    {
        $local = $this->createFullAccount('anna');
        foreach ([
            ['actor' => $this->target->uri],
            ['object' => $this->target->uri],
            ['target' => $this->source->uri],
            ['target' => $local->actor->uri],
            ['target' => ['id' => []]],
        ] as $overrides) {
            $this->assertSame(InboxItem::STATUS_IGNORED, $this->process($overrides));
        }
        $this->assertDatabaseCount('notifications', 0);
        Http::assertNothingSent();
    }

    public function test_blocked_destination_and_known_migration_chain_are_ignored(): void
    {
        $this->target->update(['moved_to_actor_id' => $this->source->id]);
        $this->assertSame(InboxItem::STATUS_IGNORED, $this->process());
        $this->target->update(['moved_to_actor_id' => null]);
        app(DomainBlockManager::class)->block($this->createFullAccount('admin', ['is_admin' => true]), 'server-b.example');
        $this->assertSame(InboxItem::STATUS_IGNORED, $this->process());
        $this->assertNull($this->source->fresh()->moved_to_actor_id);
    }

    public function test_notification_failure_rolls_back_source_marker_and_all_notifications(): void
    {
        $this->follow($this->createFullAccount('anna')->actor);
        $this->follow($this->createFullAccount('marco')->actor);
        $calls = 0;
        Notification::creating(function () use (&$calls): void {
            if (++$calls === 2) {
                throw new RuntimeException('notification failure');
            }
        });
        try {
            $this->process();
            $this->fail('Expected the notification insert to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('notification failure', $exception->getMessage());
        } finally {
            Notification::flushEventListeners();
        }
        $this->assertNull($this->source->fresh()->moved_to_actor_id);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame(0, User::query()->sum('notifications_revision'));
    }

    public function test_profile_banner_and_notification_link_to_destination_without_modifying_follows(): void
    {
        $anna = $this->createFullAccount('anna');
        $this->follow($anna->actor);
        $this->process();
        Http::fake(['*' => Http::response('', 404)]);
        $this->actingAs($anna)->get($this->source->profileUrl())
            ->assertOk()->assertSee(__('openbook.actors.moved_notice'))
            ->assertSee('href="'.$this->target->profileUrl().'"', false);
        $this->get(route('notifications.index'))->assertOk()
            ->assertSee('@pluto@server-b.example')
            ->assertSee('href="'.$this->target->profileUrl().'"', false);
        $this->assertDatabaseCount('follows', 1);
        Queue::assertNothingPushed();
    }

    public function test_normal_actor_update_clears_aliases_but_preserves_verified_destination(): void
    {
        $this->process();
        $document = $this->document([
            'id' => $this->source->uri, 'preferredUsername' => 'pippo',
            'publicKey' => [
                'id' => $this->source->uri.'#main-key',
                'owner' => $this->source->uri,
                'publicKeyPem' => $this->source->key->public_key,
            ],
        ]);
        unset($document['alsoKnownAs']);
        app(RemoteActorResolver::class)->applyRemoteDocument($document, $this->source->uri);
        $this->assertSame($this->target->id, $this->source->fresh()->moved_to_actor_id);
        $this->assertSame([], $this->source->fresh()->also_known_as);
    }

    public function test_signed_move_delivered_to_two_individual_inboxes_is_processed_once_for_both_followers(): void
    {
        $anna = $this->createFullAccount('anna');
        $marco = $this->createFullAccount('marco');
        $this->follow($anna->actor);
        $this->follow($marco->actor);
        $pair = (new RsaKeyPairGenerator)->generate(2048);
        $this->source->key->update(['public_key' => $pair->publicKey]);
        $body = json_encode($this->activity(), JSON_THROW_ON_ERROR);
        $date = now()->toRfc7231String();
        $digest = HttpSignatureSigner::digest($body);
        foreach (['/users/anna/inbox', '/users/marco/inbox'] as $path) {
            $signature = (new HttpSignatureSigner)->sign('POST', $path, [
                'host' => parse_url(url('/'), PHP_URL_HOST), 'date' => $date, 'digest' => $digest,
            ], $this->source->uri.'#main-key', $pair->privateKey, ['(request-target)', 'host', 'date', 'digest']);
            $this->call('POST', $path, [], [], [], [
                'CONTENT_TYPE' => 'application/activity+json',
                'HTTP_DATE' => $date, 'HTTP_DIGEST' => $digest, 'HTTP_SIGNATURE' => $signature,
            ], $body)->assertStatus(202);
        }
        $this->assertDatabaseCount('inbox_items', 1);
        Queue::assertPushed(ProcessInboxActivityJob::class, 1);
        (new ProcessInboxActivityJob(InboxItem::query()->sole()->id))->handle(app(InboxActivityProcessor::class));
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseCount('follows', 2);
    }
}
