<?php

namespace Tests\Concerns;

use App\Application\Services\CommunityRegistrar;
use App\Application\Services\DomainBlockManager;
use App\Application\Services\InstanceRelayActor;
use App\Application\Services\LocalPollVoter;
use App\Application\Services\PollResultsProcessor;
use App\Application\Services\PostComposer;
use App\Domain\Federation\Relay;
use App\Domain\Polls\Poll;
use App\Domain\Polls\PollDistribution;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\ActivitySerializer;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

trait TestsPollResults
{
    use CreatesAccounts, CreatesRemoteActors;

    private function pollFixture(): Poll
    {
        config(['queue.default' => 'sync']);
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));
        $author = $this->createFullAccount('author')->actor;

        return app(PostComposer::class)->compose($author, [
            'body' => 'Quando ci vediamo?',
            'poll' => ['options' => ['Sabato', 'Domenica'], 'multiple' => true, 'duration_seconds' => 300],
        ])->poll;
    }

    private function follow(Actor $follower, string $following): Follow
    {
        return Follow::create(['follower_id' => $follower->id, 'following_id' => $following,
            'status' => 'accepted', 'requested_at' => now(), 'accepted_at' => now()]);
    }

    private function jobs(): array
    {
        return DB::table('jobs')->orderBy('id')->get()->map(fn ($row): DeliverActivityJob => unserialize(json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR)['data']['command']))->all();
    }

    public function test_publication_is_durable_and_uses_existing_delivery_queue_even_with_sync_default(): void
    {
        $poll = $this->pollFixture();
        $remote = $this->createRemoteActor('follower');
        $this->follow($remote, $poll->post->actor_id);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->artisan('openbook:process-polls')->assertSuccessful();
        $job = $this->jobs()[0];
        $this->assertSame('local_delivery', $job->connection);
        $this->assertSame('delivery', $job->queue);
        $this->assertSame('Create', $job->activity['type']);
        $this->assertSame('Question', $job->activity['object']['type']);
        $this->assertSame($poll->post->actor_id, $job->signingActorId);
        $this->assertFalse($poll->fresh()->publication_pending);
        $this->assertFalse($poll->fresh()->needs_delivery);
        $this->assertSame(1, $poll->fresh()->distributed_revision);
        $this->assertSame(0, PollDistribution::count());
        Http::assertNothingSent();
        $this->artisan('openbook:process-polls')->assertSuccessful();
        $this->assertCount(1, $this->jobs());
    }

    public function test_votes_are_grouped_in_one_update_and_repeated_passes_are_idempotent(): void
    {
        $poll = $this->pollFixture();
        $remote = $this->createRemoteActor('voter');
        $service = app(PollResultsProcessor::class);
        $service->distribute($poll->id, 50);
        app(LocalPollVoter::class)->receive($remote, $poll, 'Sabato', $remote->uri.'#one', now());
        app(LocalPollVoter::class)->receive($remote, $poll, 'Domenica', $remote->uri.'#two', now());
        $service->distribute($poll->id, 50);
        $service->distribute($poll->id, 50);
        $this->assertCount(1, $this->jobs());
        $job = $this->jobs()[0];
        $this->assertSame('Update', $job->activity['type']);
        $this->assertStringEndsWith('/poll-updates/3', $job->activity['id']);
        $this->assertSame(1, $job->activity['object']['votersCount']);
        $this->assertSame([1, 1], array_column(array_column($job->activity['object']['anyOf'], 'replies'), 'totalItems'));
        $this->assertSame(3, $poll->fresh()->distributed_revision);
        $this->assertNull($poll->post->fresh()->edited_at);
    }

    public function test_partial_fanout_resumes_with_identical_snapshot_and_retains_concurrent_revision(): void
    {
        $poll = $this->pollFixture();
        foreach (['a', 'b', 'c'] as $host) {
            $this->follow($this->createRemoteActor($host, $host.'.example'), $poll->post->actor_id);
        }
        $processor = app(PollResultsProcessor::class);
        $processor->distribute($poll->id, 1);
        $first = $this->jobs()[0]->activity;
        $snapshot = PollDistribution::sole();
        $this->assertSame($first, $snapshot->activity);
        $voter = $this->createFullAccount('voter')->actor;
        app(LocalPollVoter::class)->vote($voter, $poll, [$poll->options[0]->id], $poll->optionsVersion());
        $processor->distribute($poll->id, 1);
        $processor->distribute($poll->id, 1);
        $this->assertSame([$first, $first, $first], array_map(fn ($job) => $job->activity, $this->jobs()));
        $processor->distribute($poll->id, 1);
        $this->assertSame(0, PollDistribution::count());
        $this->assertSame(1, $poll->fresh()->distributed_revision);
        $this->assertTrue($poll->fresh()->needs_delivery);
        $this->assertFalse($poll->fresh()->publication_pending);
        $processor->distribute($poll->id, 50);
        $this->assertCount(6, $this->jobs());
        $this->assertSame('Update', $this->jobs()[3]->activity['type']);
        $this->assertSame(1, $this->jobs()[3]->activity['object']['votersCount']);
        $this->assertSame(2, $poll->fresh()->distributed_revision);
        $this->assertFalse($poll->fresh()->needs_delivery);
    }

    public function test_batch_failure_rolls_back_enqueued_jobs_and_cursor_but_keeps_prior_batch(): void
    {
        $poll = $this->pollFixture();
        foreach (['a', 'b'] as $host) {
            $this->follow($this->createRemoteActor($host, $host.'.example'), $poll->post->actor_id);
        }
        $processor = app(PollResultsProcessor::class);
        $processor->distribute($poll->id, 1);
        $cursor = PollDistribution::sole()->cursor;
        try {
            DB::transaction(function () use ($processor, $poll): void {
                $processor->distribute($poll->id, 1);
                throw new RuntimeException('simulated crash');
            });
        } catch (RuntimeException) {
            $this->assertCount(1, $this->jobs());
            $this->assertSame($cursor, PollDistribution::sole()->cursor);
        }
        $processor->distribute($poll->id, 50);
        $this->assertCount(2, $this->jobs());
        $this->assertSame($this->jobs()[0]->activity, $this->jobs()[1]->activity);
        $this->assertFalse($poll->fresh()->needs_delivery);
    }

    public function test_bad_transactional_queue_configuration_preserves_pending_work(): void
    {
        $poll = $this->pollFixture();
        $this->follow($this->createRemoteActor('follower'), $poll->post->actor_id);
        config(['queue.connections.local_delivery.driver' => 'sync']);
        $this->artisan('openbook:process-polls')->assertFailed();
        $this->assertTrue($poll->fresh()->needs_delivery);
        $this->assertTrue($poll->fresh()->publication_pending);
        $this->assertSame(0, $poll->fresh()->distributed_revision);
        $this->assertSame(0, PollDistribution::count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_shared_inbox_is_deduplicated_across_followers_voters_and_mentions(): void
    {
        $poll = $this->pollFixture();
        $first = $this->createRemoteActor('first');
        $second = $this->createRemoteActor('second');
        $this->follow($first, $poll->post->actor_id);
        $this->follow($second, $poll->post->actor_id);
        app(LocalPollVoter::class)->receive($first, $poll, 'Sabato', $first->uri.'#one', now());
        $poll->post->mentions()->create(['actor_id' => $second->id]);
        app(PollResultsProcessor::class)->distribute($poll->id, 50);
        $this->assertCount(2, $this->jobs());
        $this->assertSame(['Create', 'Update'], array_column(array_map(fn ($job) => $job->activity, $this->jobs()), 'type'));
        $this->assertSame('https://remoto.example/inbox', $this->jobs()[0]->inboxUrl);
    }

    public function test_expiry_closes_without_votes_and_runs_only_once(): void
    {
        $poll = $this->pollFixture();
        $this->follow($this->createRemoteActor('follower'), $poll->post->actor_id);
        app(PollResultsProcessor::class)->distribute($poll->id, 50);
        DB::table('jobs')->delete();
        $this->travelTo($poll->ends_at);
        $this->artisan('openbook:process-polls')->assertSuccessful();
        $this->assertTrue($poll->fresh()->closed);
        $this->assertTrue($poll->fresh()->closed_at->equalTo($poll->ends_at));
        $this->assertSame('Update', $this->jobs()[0]->activity['type']);
        $this->assertSame($poll->ends_at->toAtomString(), $this->jobs()[0]->activity['object']['closed']);
        $this->artisan('openbook:process-polls')->assertSuccessful();
        $this->assertCount(1, $this->jobs());
        $this->assertSame(2, $poll->fresh()->revision);
    }

    public function test_timely_vote_processed_after_closure_creates_another_closed_update(): void
    {
        $poll = $this->pollFixture();
        $remote = $this->createRemoteActor('voter');
        $this->travelTo($poll->ends_at->copy()->addHour());
        $this->artisan('openbook:process-polls')->assertSuccessful();
        app(LocalPollVoter::class)->receive($remote, $poll, 'Sabato', $remote->uri.'#late-processing', $poll->ends_at->copy()->subSecond());
        $this->artisan('openbook:process-polls')->assertSuccessful();
        $this->assertCount(1, $this->jobs());
        $this->assertSame(1, $this->jobs()[0]->activity['object']['votersCount']);
        $this->assertSame($poll->ends_at->toAtomString(), $this->jobs()[0]->activity['object']['closed']);
        $this->assertTrue($poll->fresh()->closed);
    }

    public function test_private_community_uses_members_instead_of_author_followers_and_rechecks_revocation(): void
    {
        $poll = $this->pollFixture();
        $owner = $poll->post->actor->user;
        $community = app(CommunityRegistrar::class)->register($owner, ['slug' => 'privatepolls', 'name' => 'Private polls', 'is_private' => true]);
        $poll->post->forceFill(['community_id' => $community->id])->save();
        $member = $this->createRemoteActor('member', 'member.example');
        $outsider = $this->createRemoteActor('outsider', 'outsider.example');
        $follow = $this->follow($member, $community->actor_id);
        $this->follow($outsider, $poll->post->actor_id);
        app(PollResultsProcessor::class)->distribute($poll->id, 50);
        $this->assertCount(1, $this->jobs());
        $job = $this->jobs()[0];
        $this->assertSame($member->deliveryInboxUrl(), $job->inboxUrl);
        $this->assertNotContains('https://www.w3.org/ns/activitystreams#Public', $job->activity['object']['to']);
        $follow->delete();
        app()->call([$job, 'handle']);
        Http::assertNothingSent();
    }

    public function test_public_community_members_receive_result_updates(): void
    {
        $poll = $this->pollFixture();
        $community = app(CommunityRegistrar::class)->register($poll->post->actor->user, ['slug' => 'publicpolls', 'name' => 'Public polls']);
        $poll->post->forceFill(['community_id' => $community->id])->save();
        $this->follow($this->createRemoteActor('member'), $community->actor_id);
        app(PollResultsProcessor::class)->distribute($poll->id, 50);
        $this->assertCount(1, $this->jobs());
    }

    public function test_deleted_posts_clear_partial_work_and_suppress_previously_queued_jobs(): void
    {
        $poll = $this->pollFixture();
        $this->follow($this->createRemoteActor('follower'), $poll->post->actor_id);
        app(PollResultsProcessor::class)->distribute($poll->id, 1);
        $job = $this->jobs()[0];
        DB::transaction(fn () => $poll->post->update(['status' => Post::STATUS_DELETED]));
        $this->assertSame(0, PollDistribution::count());
        app()->call([$job, 'handle']);
        Http::assertNothingSent();
        $this->assertFalse($poll->fresh()->needs_delivery);
    }

    public function test_delivery_retries_keep_activity_identity_and_question_snapshot(): void
    {
        $poll = $this->pollFixture();
        $this->follow($this->createRemoteActor('follower'), $poll->post->actor_id);
        app(PollResultsProcessor::class)->distribute($poll->id, 50);
        $job = $this->jobs()[0];
        Http::fakeSequence()->push('', 503)->push('', 202);
        try {
            app()->call([$job, 'handle']);
            $this->fail('Retry expected.');
        } catch (RuntimeException) {
            $this->assertFalse($poll->fresh()->needs_delivery);
        }
        app()->call([$job, 'handle']);
        $bodies = Http::recorded()->map(fn ($request) => json_decode($request[0]->body(), true))->all();
        $this->assertSame($bodies[0]['id'], $bodies[1]['id']);
        $this->assertSame($bodies[0]['object'], $bodies[1]['object']);
        $this->assertArrayHasKey('signature', $bodies[0]);
        $this->assertTrue(Http::recorded()[0][0]->hasHeader('Signature'));
    }

    public function test_suspended_author_retains_pending_work_for_resumption(): void
    {
        $poll = $this->pollFixture();
        $poll->post->actor->update(['status' => Actor::STATUS_SUSPENDED]);
        app(PollResultsProcessor::class)->distribute($poll->id, 50);
        $this->assertTrue($poll->fresh()->needs_delivery);
        $this->assertSame(0, $poll->fresh()->distributed_revision);
        $this->assertSame(0, PollDistribution::count());
    }

    public function test_missing_inbox_is_resolved_by_delivery_outside_poll_transaction(): void
    {
        $poll = $this->pollFixture();
        $remote = $this->createRemoteActor('follower');
        $this->follow($remote, $poll->post->actor_id);
        $remote->endpoints->update(['inbox' => null, 'shared_inbox' => null]);
        app(PollResultsProcessor::class)->distribute($poll->id, 50);
        $job = $this->jobs()[0];
        $this->assertSame('', $job->inboxUrl);
        $this->assertSame($remote->id, $job->pollRecipientActorId);
        Http::assertNothingSent();
        $transactionLevel = DB::transactionLevel();
        Http::fake(function (Request $request) use ($remote, $transactionLevel) {
            $this->assertSame($transactionLevel, DB::transactionLevel());
            if ($request->url() === $remote->uri) {
                return Http::response(['id' => $remote->uri, 'type' => 'Person',
                    'preferredUsername' => 'follower', 'inbox' => 'https://remoto.example/inbox',
                    'publicKey' => ['id' => $remote->uri.'#main-key', 'owner' => $remote->uri,
                        'publicKeyPem' => $remote->key->public_key]]);
            }
            $this->assertSame('https://remoto.example/inbox', $request->url());

            return Http::response('', 202);
        });
        app()->call([$job, 'handle']);
        Http::assertSentCount(2);
        $this->assertSame('https://remoto.example/inbox', $remote->fresh()->endpoints->inbox);
    }

    public function test_public_mastodon_relay_is_durable_and_restricted_polls_are_excluded(): void
    {
        $poll = $this->pollFixture();
        Relay::create(['protocol' => Relay::PROTOCOL_MASTODON, 'inbox_url' => 'https://relay.example/inbox',
            'inbox_url_hash' => hash('sha256', 'https://relay.example/inbox'), 'state' => Relay::STATE_ACCEPTED,
            'publish_enabled' => true, 'receive_enabled' => true]);
        app(PollResultsProcessor::class)->distribute($poll->id, 50);
        $this->assertCount(1, $this->jobs());
        $this->assertNotNull($this->jobs()[0]->relayId);
        DB::table('jobs')->delete();
        $poll->post->update(['visibility' => Post::VISIBILITY_FOLLOWERS]);
        $poll->forceFill(['needs_delivery' => true, 'revision' => 2])->save();
        app(PollResultsProcessor::class)->distribute($poll->id, 50);
        $this->assertCount(0, $this->jobs());
    }

    public function test_actor_relays_keep_the_existing_announce_format_and_technical_signer(): void
    {
        $poll = $this->pollFixture();
        $relay = app(InstanceRelayActor::class)->getOrCreate();
        $remote = $this->createRemoteActor('application', 'relay.example', ['type' => Actor::TYPE_APPLICATION]);
        $this->follow($remote, $relay->id);
        app(PollResultsProcessor::class)->distribute($poll->id, 50);
        $job = $this->jobs()[0];
        $this->assertSame('Announce', $job->activity['type']);
        $this->assertSame($relay->id, $job->signingActorId);
        $this->assertSame($poll->post_id, $job->localPollPostId);
        Http::fake(['https://relay.example/inbox' => Http::response('', 202)]);
        app()->call([$job, 'handle']);
        Http::assertSentCount(1);
    }

    public function test_domain_block_added_after_enqueue_stops_delivery(): void
    {
        $poll = $this->pollFixture();
        $remote = $this->createRemoteActor('follower');
        $this->follow($remote, $poll->post->actor_id);
        app(PollResultsProcessor::class)->distribute($poll->id, 50);
        $owner = $poll->post->actor->user;
        $owner->forceFill(['is_admin' => true])->save();
        app(DomainBlockManager::class)->block($owner, 'remoto.example');
        app()->call([$this->jobs()[0], 'handle']);
        Http::assertNothingSent();
    }

    public function test_inbox_host_block_added_after_enqueue_stops_delivery_even_when_actor_host_differs(): void
    {
        $poll = $this->pollFixture();
        $remote = $this->createRemoteActor('follower');
        $remote->endpoints->update(['shared_inbox' => 'https://delivery.example/inbox']);
        $this->follow($remote, $poll->post->actor_id);
        app(PollResultsProcessor::class)->distribute($poll->id, 50);
        $this->assertSame('https://delivery.example/inbox', $this->jobs()[0]->inboxUrl);
        $owner = $poll->post->actor->user;
        $owner->forceFill(['is_admin' => true])->save();
        app(DomainBlockManager::class)->block($owner, 'delivery.example');
        app()->call([$this->jobs()[0], 'handle']);
        Http::assertNothingSent();
    }

    public function test_poll_text_edits_use_the_same_pending_distribution_path(): void
    {
        $poll = $this->pollFixture();
        $this->follow($this->createRemoteActor('follower'), $poll->post->actor_id);
        app(PollResultsProcessor::class)->distribute($poll->id, 50);
        DB::table('jobs')->delete();
        app(PostComposer::class)->update($poll->post->actor, $poll->post, ['body' => 'Domanda corretta']);
        $this->assertCount(0, $this->jobs());
        $this->assertTrue($poll->fresh()->needs_delivery);
        app(PollResultsProcessor::class)->distribute($poll->id, 50);
        $this->assertSame('Update', $this->jobs()[0]->activity['type']);
        $this->assertStringContainsString('Domanda corretta', $this->jobs()[0]->activity['object']['content']);
    }

    public function test_current_audience_change_stops_delivery_of_a_stale_public_payload(): void
    {
        $poll = $this->pollFixture();
        $remote = $this->createRemoteActor('follower');
        $this->follow($remote, $poll->post->actor_id);
        app(PollResultsProcessor::class)->distribute($poll->id, 50);
        // Defensive guard for operator changes outside the immutable publication flow.
        $poll->post->update(['visibility' => Post::VISIBILITY_FOLLOWERS]);
        app()->call([$this->jobs()[0], 'handle']);
        Http::assertNothingSent();
    }

    public function test_migration_rollback_protects_an_active_fanout_then_succeeds_after_completion(): void
    {
        $poll = $this->pollFixture();
        $this->follow($this->createRemoteActor('follower'), $poll->post->actor_id);
        app(PollResultsProcessor::class)->distribute($poll->id, 1);
        $migration = require base_path('database/migrations/2026_10_15_000004_create_poll_distributions_table.php');
        try {
            $migration->down();
            $this->fail('An active snapshot must be retained.');
        } catch (\LogicException) {
            $this->assertSame(1, PollDistribution::count());
        }
        app(PollResultsProcessor::class)->distribute($poll->id, 50);
        $migration->down();
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('poll_distributions'));
        $migration->up();
        $this->assertSame(0, PollDistribution::count());
        $this->assertFalse($poll->fresh()->needs_delivery);
        $this->assertCount(1, $this->jobs());
    }

    public function test_previously_queued_create_does_not_hide_votes_accepted_before_cron_snapshot(): void
    {
        $poll = $this->pollFixture();
        $remote = $this->createRemoteActor('voter');
        $this->follow($remote, $poll->post->actor_id);
        DB::transaction(fn () => app(ActivityDelivery::class)->enqueueDirectInTransaction(
            $poll->post->actor, $remote->deliveryInboxUrl(), ActivitySerializer::create($poll->post),
        ));
        app(LocalPollVoter::class)->receive($remote, $poll, 'Sabato', $remote->uri.'#one', now());
        app(PollResultsProcessor::class)->distribute($poll->id, 50);
        $jobs = $this->jobs();
        $this->assertCount(3, $jobs);
        $this->assertSame($jobs[0]->activity['id'], $jobs[1]->activity['id']);
        $this->assertSame('Update', $jobs[2]->activity['type']);
        $this->assertNotSame($jobs[0]->activity['id'], $jobs[2]->activity['id']);
        $this->assertSame(1, $jobs[2]->activity['object']['votersCount']);
        $this->assertSame(2, $poll->fresh()->distributed_revision);
    }

    public function test_command_limits_bound_work_and_remote_polls_are_left_to_their_origin(): void
    {
        $poll = $this->pollFixture();
        $this->follow($this->createRemoteActor('a', 'a.example'), $poll->post->actor_id);
        $this->follow($this->createRemoteActor('b', 'b.example'), $poll->post->actor_id);
        $this->artisan('openbook:process-polls', ['--limit' => 1, '--batch-size' => 1, '--max-time' => 1])->assertSuccessful();
        $this->assertCount(1, $this->jobs());
        $this->assertSame(1, PollDistribution::count());
        $poll->post->update(['uri' => 'https://remote.example/poll']);
        $this->travelTo($poll->ends_at);
        $this->artisan('openbook:process-polls')->assertSuccessful();
        $this->assertNull($poll->fresh()->closed_at);
        $this->assertCount(1, $this->jobs());
    }
}
