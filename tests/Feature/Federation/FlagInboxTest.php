<?php

namespace Tests\Feature\Federation;

use App\Application\Services\DomainBlockManager;
use App\Domain\Comments\Comment;
use App\Domain\Events\Event;
use App\Domain\Events\EventComment;
use App\Domain\Federation\Relay;
use App\Domain\Moderation\Report;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Inbox\InboxItem;
use App\Federation\Resolution\ObjectResolver;
use App\Infrastructure\Security\HttpSignatureSigner;
use App\Infrastructure\Security\KeyPair;
use App\Infrastructure\Security\LinkedData\LinkedDataSignature;
use App\Infrastructure\Security\RsaKeyPairGenerator;
use App\Jobs\Federation\DeliverActivityJob;
use App\Jobs\Federation\ProcessInboxActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class FlagInboxTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    private Actor $sender;

    private KeyPair $key;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake([]);
        Http::preventStrayRequests();
        $this->sender = $this->createRemoteActor('instance', 'remote.example', ['type' => Actor::TYPE_APPLICATION]);
        $this->key = (new RsaKeyPairGenerator)->generate(2048);
        $this->sender->key->update(['public_key' => $this->key->publicKey, 'private_key' => $this->key->privateKey]);
    }

    private function activity(): array
    {
        $activity = json_decode(file_get_contents(base_path('tests/Fixtures/Federation/Reports/flag.json')), true, 32, JSON_THROW_ON_ERROR);
        $author = $this->createFullAccount('reported')->actor;
        $objects = $activity['object'];
        $post = Post::query()->forceCreate(['id' => basename($objects[1]), 'actor_id' => $author->id,
            'body' => 'Local post', 'visibility' => 'public', 'status' => 'published', 'published_at' => now()]);
        Comment::query()->forceCreate(['id' => basename($objects[2]), 'actor_id' => $author->id,
            'post_id' => $post->id, 'body' => 'Local comment', 'status' => 'published']);
        $event = Event::query()->forceCreate(['id' => basename($objects[3]), 'uri' => $objects[3],
            'actor_id' => $author->id, 'name' => 'Local event', 'visibility' => 'public',
            'start_at' => now()->addDay(), 'status' => 'scheduled']);
        EventComment::query()->forceCreate(['id' => basename($objects[4]), 'uri' => $objects[4],
            'actor_id' => $author->id, 'event_id' => $event->id, 'body' => 'Event comment', 'status' => 'published']);

        return $activity;
    }

    private function send(array $activity, string $path = '/inbox', ?Actor $transport = null, ?KeyPair $key = null): TestResponse
    {
        $transport ??= $this->sender;
        $key ??= $this->key;
        $body = json_encode($activity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $date = now()->toRfc7231String();
        $digest = HttpSignatureSigner::digest($body);
        $signature = (new HttpSignatureSigner)->sign('POST', $path, [
            'host' => parse_url(url('/'), PHP_URL_HOST), 'date' => $date, 'digest' => $digest,
        ], $transport->uri.'#main-key', $key->privateKey, ['(request-target)', 'host', 'date', 'digest']);

        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/activity+json', 'HTTP_DATE' => $date,
            'HTTP_DIGEST' => $digest, 'HTTP_SIGNATURE' => $signature,
        ], $body);
    }

    private function process(string $activityUri): InboxItem
    {
        $item = InboxItem::query()->where('remote_activity_uri', $activityUri)->firstOrFail();
        app()->call([new ProcessInboxActivityJob($item->id), 'handle']);

        return $item->fresh();
    }

    #[DataProvider('inboxes')]
    public function test_a_signed_flag_reaches_the_normal_job_and_stores_all_five_types(string $path): void
    {
        $activity = $this->activity();
        $activity['content'] = '<p>Reason &amp; evidence</p>';
        $this->send($activity, $path)->assertStatus(202);
        $this->assertDatabaseCount('reports', 0);
        Queue::assertPushed(ProcessInboxActivityJob::class, 1);
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($activity['id'])->status);
        $report = Report::sole();
        $this->assertSame($this->sender->uri, $report->reporter_uri);
        $this->assertSame('Reason & evidence', $report->details);
        $this->assertSame(Report::STATUS_OPEN, $report->status);
        $this->assertEqualsCanonicalizing(['actor', 'post', 'comment', 'event', 'event_comment'], $report->targets->pluck('type')->all());
        $this->assertSame(0, $report->targets()->where('unavailable', true)->count());
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNotPushed(DeliverActivityJob::class);
        Http::assertNothingSent();
    }

    public static function inboxes(): array
    {
        return [['/inbox'], ['/users/reported/inbox']];
    }

    public function test_a_profile_only_flag_accepts_embedded_ids_and_inactive_local_people(): void
    {
        $activity = $this->activity();
        Actor::query()->where('is_local', true)->update(['status' => Actor::STATUS_SUSPENDED]);
        $activity['object'] = ['id' => 'https://openbook.test/@reported', 'type' => 'Person', 'name' => 'Untrusted name'];
        unset($activity['content']);
        $this->send($activity)->assertStatus(202);
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($activity['id'])->status);
        $report = Report::sole();
        $this->assertSame(Report::STATUS_OPEN, $report->status);
        $this->assertNull($report->details);
        $this->assertCount(1, $report->targets);
        $this->assertSame('reported', $report->targets->sole()->actor->preferred_username);
        $this->assertNotSame('Untrusted name', $report->targets->sole()->actor->name);
    }

    public function test_profile_flags_resolve_when_the_local_domain_includes_a_development_port(): void
    {
        $activity = $this->activity();
        config(['openbook.domain' => 'openbook.test:8443']);
        $activity['object'] = 'https://openbook.test:8443/users/reported';
        $this->send($activity)->assertStatus(202);
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($activity['id'])->status);
        $target = Report::sole()->targets()->sole();
        $this->assertSame('reported', $target->actor->preferred_username);
        // The ordinary profile-alias resolver follows the same host comparison.
        $alias = app(ObjectResolver::class)->resolveActor('https://openbook.test:8443/@reported');
        $this->assertTrue($target->actor->is($alias));
        Http::assertNothingSent();
    }

    public function test_redelivery_across_inboxes_jobs_and_inbox_cleanup_preserves_a_closed_case(): void
    {
        $activity = $this->activity();
        $this->send($activity)->assertStatus(202);
        $this->send($activity, '/users/reported/inbox')->assertStatus(202);
        $item = $this->process($activity['id']);
        $report = Report::sole();
        $report->update(['status' => Report::STATUS_DISMISSED, 'closed_at' => now()]);
        $item->update(['status' => InboxItem::STATUS_PENDING]);
        $this->process($activity['id']);
        $item->delete();
        $activity['content'] = 'Changed reason';
        $this->send($activity)->assertStatus(202);
        $this->process($activity['id']);
        $this->assertDatabaseCount('reports', 1);
        $this->assertSame('Reason', $report->fresh()->details);
        $this->assertSame(Report::STATUS_DISMISSED, $report->fresh()->status);
        $this->assertCount(5, $report->fresh()->targets);

        $report->delete();
        InboxItem::query()->delete();
        $this->send($activity)->assertStatus(202);
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($activity['id'])->status);
        $this->assertDatabaseCount('reports', 0);
        $this->assertDatabaseCount('report_receipts', 1);
    }

    public function test_signed_flag_review_action_cleanup_and_redelivery_complete_the_same_normal_flow(): void
    {
        $activity = $this->activity();
        $this->send($activity)->assertStatus(202);
        $this->process($activity['id']);
        $report = Report::sole();
        $moderator = $this->createFullAccount('moderator');
        $moderator->forceFill(['is_moderator' => true])->save();
        $target = $report->targets()->where('type', 'post')->sole();
        $this->actingAs($moderator)->get(route('admin.reports.show', $report))->assertOk();
        $this->post(route('admin.reports.review', $report))->assertRedirect();
        $this->post(route('admin.reports.action', $report), ['targets' => [$target->id]])->assertRedirect();
        $this->assertSame('deleted', Post::sole()->status);
        $this->assertSame('actioned', $report->fresh()->status);
        $this->travelTo(now()->addMonthsNoOverflow(13));
        $this->artisan('openbook:purge-database', ['--force' => true])->assertSuccessful();
        $this->assertDatabaseCount('reports', 0);
        $this->assertDatabaseCount('report_targets', 0);
        $this->assertDatabaseCount('inbox_items', 0);
        $this->assertDatabaseHas('report_receipts', ['activity_hash' => hash('sha256', $activity['id']), 'report_id' => null]);
        // The HTTP verifier uses the real clock; only cleanup needs simulated ageing.
        $this->travelBack();
        $activity['content'] = 'Changed motivation after cleanup';
        $this->send($activity)->assertStatus(202);
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($activity['id'])->status);
        $this->assertDatabaseCount('reports', 0);
        $this->assertDatabaseCount('report_targets', 0);
        $this->assertDatabaseCount('report_receipts', 1);
        $this->assertDatabaseCount('notifications', 0);
        Http::assertNothingSent();
    }

    public function test_missing_and_deleted_content_is_already_handled_while_mixed_cases_stay_open(): void
    {
        $activity = $this->activity();
        $post = Post::sole();
        $post->update(['status' => Post::STATUS_DELETED]);
        $activity['object'] = [$activity['object'][0], $activity['object'][1],
            'https://openbook.test/comments/'.Str::uuid(), 'https://openbook.test/eventi/'.Str::uuid(),
            'https://openbook.test/event-comments/'.Str::uuid()];
        $this->send($activity)->assertStatus(202);
        $this->process($activity['id']);
        $report = Report::sole();
        $this->assertSame(Report::STATUS_ACTIONED, $report->status);
        $this->assertSame(Report::CLOSURE_UNAVAILABLE, $report->closure_reason);
        $this->assertNull($report->reviewed_by);
        $this->assertCount(5, $report->targets);

        $activity['id'] .= '/mixed';
        $activity['object'][] = Event::sole()->uri;
        $this->send($activity)->assertStatus(202);
        $this->process($activity['id']);
        $this->assertSame(Report::STATUS_OPEN, Report::query()->where('remote_activity_uri', $activity['id'])->sole()->status);
        $this->assertSame(Post::STATUS_DELETED, $post->fresh()->status);
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNotPushed(DeliverActivityJob::class);
    }

    public function test_private_content_is_recorded_without_changing_visibility_or_treating_it_as_missing(): void
    {
        $activity = $this->activity();
        Post::sole()->update(['visibility' => 'direct']);
        Event::sole()->update(['visibility' => 'followers']);
        $activity['object'] = [$activity['object'][1], $activity['object'][3]];
        $this->send($activity)->assertStatus(202);
        $this->process($activity['id']);
        $this->assertSame(Report::STATUS_OPEN, Report::sole()->status);
        $this->assertSame(0, Report::sole()->targets()->where('unavailable', true)->count());
        $this->assertSame('direct', Post::sole()->visibility);
        $this->assertSame('followers', Event::sole()->visibility);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_unknown_remote_and_community_targets_are_ignored_without_importing_them(): void
    {
        $activity = $this->activity();
        $remotePost = Post::sole()->replicate();
        $remotePost->actor_id = $this->sender->id;
        $remotePost->uri = 'https://remote.example/posts/known';
        $remotePost->save();
        $group = $this->createRemoteActor('group', 'openbook.test', ['is_local' => true, 'type' => Actor::TYPE_GROUP]);
        $activity['object'] = [$this->sender->uri, $remotePost->uri, $group->uri,
            'https://openbook.test/posts/'.$remotePost->id, 'https://openbook.test/settings',
            'https://openbook.test/posts/not-a-uuid', 'https://unknown.example/objects/one',
            null, false, ['type' => 'Note', 'content' => 'No identifier']];
        $this->send($activity)->assertStatus(202);
        $this->assertSame(InboxItem::STATUS_IGNORED, $this->process($activity['id'])->status);
        $this->assertDatabaseCount('reports', 0);
        $this->assertDatabaseCount('report_receipts', 0);
        $this->assertDatabaseCount('posts', 2);
        Http::assertNothingSent();
    }

    public function test_invalid_signature_and_blocked_sender_follow_normal_inbox_rejection(): void
    {
        $activity = $this->activity();
        $badKey = (new RsaKeyPairGenerator)->generate(2048);
        $this->send($activity, key: $badKey)->assertStatus(401);
        $this->assertDatabaseCount('inbox_items', 0);
        $admin = $this->createFullAccount('admin');
        $admin->forceFill(['is_admin' => true])->save();
        app(DomainBlockManager::class)->block($admin, 'remote.example');
        $this->send($activity)->assertStatus(403);
        $this->assertDatabaseCount('inbox_items', 0);
        $this->assertDatabaseCount('reports', 0);
    }

    public function test_valid_local_targets_survive_a_mixed_list_and_keep_their_real_authors(): void
    {
        $activity = $this->activity();
        $other = $this->createFullAccount('other')->actor;
        $comment = Comment::sole();
        $comment->update(['actor_id' => $other->id]);
        $activity['object'] = [
            ['id' => $activity['object'][1], 'type' => 'Note', 'content' => 'Invented body', 'attributedTo' => $other->uri],
            $activity['object'][1], $activity['object'][2],
            'https://remote.example/posts/external', false,
        ];
        $this->send($activity)->assertStatus(202);
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($activity['id'])->status);
        $report = Report::sole();
        $this->assertCount(2, $report->targets);
        $this->assertSame('Local post', $report->targets->firstWhere('type', 'post')->post->body);
        $this->assertNotSame($other->id, $report->targets->firstWhere('type', 'post')->post->actor_id);
        $this->assertSame($other->id, $report->targets->firstWhere('type', 'comment')->comment->actor_id);
    }

    public function test_a_flag_forwarded_by_a_relay_uses_the_existing_linked_data_authentication(): void
    {
        $activity = $this->activity();
        $transport = $this->createRemoteActor('relay', 'relay.example', ['type' => Actor::TYPE_APPLICATION]);
        $transportKey = (new RsaKeyPairGenerator)->generate(2048);
        $transport->key->update(['public_key' => $transportKey->publicKey]);
        $relay = Relay::query()->create(['protocol' => Relay::PROTOCOL_MASTODON, 'actor_uri' => $transport->uri,
            'inbox_url' => 'https://relay.example/inbox', 'inbox_url_hash' => hash('sha256', 'https://relay.example/inbox'),
            'state' => Relay::STATE_ACCEPTED, 'receive_enabled' => true, 'publish_enabled' => false]);
        $activity = app(LinkedDataSignature::class)->sign($activity, $this->sender->fresh('key'));
        $this->send($activity, transport: $transport, key: $transportKey)->assertStatus(202);
        $item = $this->process($activity['id']);
        $this->assertSame($relay->id, $item->relay_id);
        $this->assertSame(InboxItem::STATUS_PROCESSED, $item->status);
        $this->assertSame($this->sender->uri, Report::sole()->reporter_uri);
        Http::assertNothingSent();
    }

    public function test_a_forwarded_flag_uses_the_existing_origin_refetch_and_its_authenticated_content(): void
    {
        $activity = $this->activity();
        $origin = $this->createRemoteActor('origin', 'origin.example');
        $activity['actor'] = $origin->uri;
        $activity['id'] = 'https://origin.example/reports/one';
        $authenticated = [...$activity, 'content' => 'Authenticated reason'];
        Http::fake([$activity['id'] => Http::response($authenticated, 200, ['Content-Type' => 'application/activity+json'])]);
        $this->send($activity)->assertStatus(202);
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($activity['id'])->status);
        $this->assertSame($origin->uri, Report::sole()->reporter_uri);
        $this->assertSame('Authenticated reason', Report::sole()->details);
        Http::assertSent(fn ($request) => $request->url() === $activity['id']);
    }

    public function test_a_string_target_and_long_motivation_fit_the_existing_inbox_payload_limit(): void
    {
        $activity = $this->activity();
        $activity['object'] = $activity['object'][1];
        $activity['content'] = str_repeat('é', 40000);
        $this->send($activity)->assertStatus(202);
        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($activity['id'])->status);
        $this->assertSame($activity['content'], Report::sole()->details);
    }
}
