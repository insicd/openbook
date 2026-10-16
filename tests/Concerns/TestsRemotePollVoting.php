<?php

namespace Tests\Concerns;

use App\Application\Services\DomainBlockManager;
use App\Application\Services\RemotePollVoter;
use App\Domain\Polls\RemotePollVote;
use App\Domain\Polls\RemotePollVoteChoice;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use RuntimeException;

trait TestsRemotePollVoting
{
    use CreatesRemotePolls;

    public function test_vote_is_complete_on_database_enqueue_even_when_global_driver_is_sync(): void
    {
        [$voter, $poll] = $this->votingFixture();
        config(['queue.default' => 'sync']);
        $before = $poll->voters_count;
        $vote = app(RemotePollVoter::class)->vote($voter, $poll, [$poll->options[1]->id], $poll->optionsVersion());
        $this->assertSame(['Domenica'], $vote->choices->pluck('name')->all());
        $this->assertSame($before, $poll->fresh()->voters_count);
        $this->assertSame(1, DB::table('jobs')->count());
        $job = $this->votingJob();
        $choice = $vote->choices->sole();
        $this->assertSame($voter->uri.'#votes/'.$choice->id.'/activity', $job->activity['id']);
        $this->assertSame($voter->uri.'#votes/'.$choice->id, $job->activity['object']['id']);
        $this->assertSame('Domenica', $job->activity['object']['name']);
        $this->assertSame($poll->post->uri, $job->activity['object']['inReplyTo']);
        $this->assertSame([$poll->post->actor->uri], $job->activity['to']);
        $this->assertSame($job->activity['to'], $job->activity['object']['to']);
        $this->assertArrayNotHasKey('cc', $job->activity);
        $this->assertFalse($job->afterCommit);
        Http::assertNothingSent();
    }

    public function test_votes_use_domain_database_even_with_a_different_global_queue_connection(): void
    {
        [$voter, $poll] = $this->votingFixture();
        config(['queue.default' => 'database', 'queue.connections.database.connection' => 'unused-external-connection']);
        app(RemotePollVoter::class)->vote($voter, $poll, [$poll->options[0]->id], $poll->optionsVersion());
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame('local_delivery', $this->votingJob()->connection);
    }

    public function test_blocked_origin_rejects_a_first_vote_before_enqueue(): void
    {
        [$voter, $poll] = $this->votingFixture();
        $voter->user->forceFill(['is_admin' => true])->save();
        app(DomainBlockManager::class)->block($voter->user, 'remote.example');
        $this->expectException(AuthorizationException::class);
        try {
            app(RemotePollVoter::class)->vote($voter, $poll, [$poll->options[0]->id], $poll->optionsVersion());
        } finally {
            $this->assertSame(0, RemotePollVote::count());
            $this->assertSame(0, DB::table('jobs')->count());
        }
    }

    public function test_partial_multiple_delivery_does_not_reopen_or_recount_the_vote(): void
    {
        [$voter, $poll] = $this->votingFixture(true);
        $count = $poll->voters_count;
        $vote = app(RemotePollVoter::class)->vote($voter, $poll, $poll->options->take(2)->pluck('id')->all(), $poll->optionsVersion());
        Http::fakeSequence()->push('', 202)->push('', 503);
        $jobs = DB::table('jobs')->orderBy('id')->get();
        app()->call([$this->decodeVotingJob($jobs[0]->payload), 'handle']);
        try {
            app()->call([$this->decodeVotingJob($jobs[1]->payload), 'handle']);
            $this->fail('Second delivery should retry.');
        } catch (RuntimeException) {
            $this->assertSame(2, $vote->fresh()->choices->count());
            $this->assertSame($count, $poll->fresh()->voters_count);
        }
        $this->assertSame($vote->id, app(RemotePollVoter::class)->vote($voter, $poll, [$poll->options[2]->id], $poll->optionsVersion())->id);
        $this->assertSame(2, DB::table('jobs')->count());
    }

    public function test_duplicate_vote_returns_original_choices_even_after_closure(): void
    {
        [$voter, $poll] = $this->votingFixture();
        $service = app(RemotePollVoter::class);
        $first = $service->vote($voter, $poll, [$poll->options[0]->id], $poll->optionsVersion());
        $poll->update(['closed' => true]);
        $second = $service->vote($voter, $poll, [$poll->options[1]->id], 'old-version');
        $this->assertSame($first->id, $second->id);
        $this->assertSame(['Sabato'], $second->choices->pluck('name')->all());
        $this->assertSame(1, RemotePollVote::count());
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_multiple_vote_enqueues_one_stable_activity_per_choice_without_fanout(): void
    {
        [$voter, $poll] = $this->votingFixture(true);
        $before = $poll->options->pluck('votes_count')->all();
        $vote = app(RemotePollVoter::class)->vote($voter, $poll, [$poll->options[2]->id, $poll->options[0]->id], $poll->optionsVersion());
        $this->assertSame(2, $vote->choices->count());
        $this->assertSame(2, DB::table('jobs')->count());
        $this->assertSame($before, $poll->fresh()->options->pluck('votes_count')->all());
        $ids = [];
        foreach (DB::table('jobs')->orderBy('id')->get() as $row) {
            $job = $this->decodeVotingJob($row->payload);
            $ids[] = $job->activity['id'];
            $this->assertSame('https://remote.example/inbox', $job->inboxUrl);
            $this->assertSame([$poll->post->actor->uri], $job->activity['to']);
        }
        $this->assertCount(2, array_unique($ids));
        app(RemotePollVoter::class)->vote($voter, $poll, [$poll->options[1]->id], $poll->optionsVersion());
        $this->assertSame(2, DB::table('jobs')->count());
    }

    public function test_queue_failure_rolls_back_all_choices_and_the_first_job(): void
    {
        [$voter, $poll] = $this->votingFixture(true);
        $calls = 0;
        Queue::createPayloadUsing(function () use (&$calls): array {
            if (++$calls === 2) {
                throw new RuntimeException('Second enqueue failed');
            }

            return [];
        });
        try {
            app(RemotePollVoter::class)->vote($voter, $poll, $poll->options->take(2)->pluck('id')->all(), $poll->optionsVersion());
            $this->fail('Queue error did not propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Second enqueue failed', $exception->getMessage());
        } finally {
            Queue::createPayloadUsing(null);
        }
        $this->assertSame(0, RemotePollVote::count());
        $this->assertSame(0, RemotePollVoteChoice::count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_expiry_and_explicit_closure_reject_a_first_vote(): void
    {
        [$voter, $poll] = $this->votingFixture();
        $poll->update(['ends_at' => now()]);
        $this->assertVotingValidation(fn () => app(RemotePollVoter::class)->vote($voter, $poll, [$poll->options[0]->id], $poll->optionsVersion()));
        $poll->update(['ends_at' => null, 'closed' => true]);
        $this->assertVotingValidation(fn () => app(RemotePollVoter::class)->vote($voter, $poll, [$poll->options[0]->id], $poll->optionsVersion()));
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_choices_are_bound_to_current_poll_and_single_choice_mode(): void
    {
        [$voter, $poll] = $this->votingFixture();
        $service = app(RemotePollVoter::class);
        $version = $poll->optionsVersion();
        foreach ([[], ['other-poll-option'], [$poll->options[0]->id, $poll->options[0]->id], $poll->options->take(2)->pluck('id')->all()] as $choices) {
            $this->assertVotingValidation(fn () => $service->vote($voter, $poll, $choices, $version));
        }
        $this->assertSame(0, RemotePollVote::count());
    }

    public function test_option_version_changes_for_rename_reorder_or_mode_but_not_results(): void
    {
        [$voter, $poll] = $this->votingFixture();
        $version = $poll->optionsVersion();
        $poll->options[0]->update(['votes_count' => 99]);
        $poll->update(['voters_count' => 99]);
        $this->assertSame($version, $poll->fresh()->optionsVersion());
        $poll->update(['multiple' => true]);
        $this->assertNotSame($version, $poll->fresh()->optionsVersion());
        $poll->update(['multiple' => false]);
        $poll->options[0]->update(['position' => 10]);
        $this->assertNotSame($version, $poll->fresh()->optionsVersion());
        $poll->options[0]->update(['position' => 0]);
        $poll->options[0]->update(['name' => 'Venerdì']);
        $this->assertNotSame($version, $poll->fresh()->optionsVersion());
        $this->assertVotingValidation(fn () => app(RemotePollVoter::class)->vote($voter, $poll, [$poll->options[0]->id], $version));
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_access_is_rechecked_for_follower_only_and_deleted_posts(): void
    {
        [$voter, $poll] = $this->votingFixture();
        $poll->post->update(['visibility' => 'followers']);
        try {
            app(RemotePollVoter::class)->vote($voter, $poll, [$poll->options[0]->id], $poll->optionsVersion());
            $this->fail('Private post accepted without a follow.');
        } catch (ModelNotFoundException) {
            $this->assertSame(0, RemotePollVote::count());
        }
        $poll->post->update(['visibility' => 'public', 'status' => 'deleted']);
        $this->expectException(ModelNotFoundException::class);
        app(RemotePollVoter::class)->vote($voter, $poll, [$poll->options[0]->id], $poll->optionsVersion());
    }

    public function test_remote_suspension_or_inactive_local_account_prevents_voting(): void
    {
        [$voter, $poll] = $this->votingFixture();
        $poll->post->actor->forceFill(['remote_suspended' => true])->save();
        try {
            app(RemotePollVoter::class)->vote($voter, $poll, [$poll->options[0]->id], $poll->optionsVersion());
            $this->fail('Suspended origin accepted.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('jobs')->count());
        }
        $poll->post->actor->forceFill(['remote_suspended' => false])->save();
        $voter->user->update(['status' => 'disabled']);
        $this->expectException(AuthorizationException::class);
        app(RemotePollVoter::class)->vote($voter, $poll, [$poll->options[0]->id], $poll->optionsVersion());
    }

    public function test_unsafe_endpoint_and_missing_signing_key_do_not_record_a_vote(): void
    {
        [$voter, $poll] = $this->votingFixture();
        $poll->post->actor->endpoints()->update(['shared_inbox' => 'https://127.0.0.1/inbox']);
        $this->assertVotingValidation(fn () => app(RemotePollVoter::class)->vote($voter, $poll, [$poll->options[0]->id], $poll->optionsVersion()));
        $poll->post->actor->endpoints()->update(['shared_inbox' => 'https://remote.example/inbox']);
        $voter->key()->delete();
        $this->assertVotingValidation(fn () => app(RemotePollVoter::class)->vote($voter, $poll, [$poll->options[0]->id], $poll->optionsVersion()));
        $this->assertSame(0, RemotePollVote::count());
    }

    public function test_retry_uses_the_same_activity_and_never_changes_the_local_vote(): void
    {
        [$voter, $poll] = $this->votingFixture();
        $vote = app(RemotePollVoter::class)->vote($voter, $poll, [$poll->options[0]->id], $poll->optionsVersion());
        $job = $this->votingJob();
        Http::fakeSequence()->push('', 503)->push('', 202);
        try {
            app()->call([$job, 'handle']);
            $this->fail('503 should request retry.');
        } catch (RuntimeException) {
            $this->assertSame($vote->id, RemotePollVote::sole()->id);
        }
        app()->call([$job, 'handle']);
        $requests = Http::recorded()->map(fn ($pair) => json_decode($pair[0]->body(), true)['id'])->all();
        $this->assertSame([$job->activity['id'], $job->activity['id']], $requests);
        $this->assertSame('Sabato', $vote->fresh()->choices->sole()->name);
    }

    public function test_block_added_after_enqueue_stops_delivery_without_reopening_the_vote(): void
    {
        [$voter, $poll] = $this->votingFixture();
        app(RemotePollVoter::class)->vote($voter, $poll, [$poll->options[0]->id], $poll->optionsVersion());
        $voter->user->forceFill(['is_admin' => true])->save();
        app(DomainBlockManager::class)->block($voter->user, 'remote.example');
        $job = $this->votingJob()->withFakeQueueInteractions();
        app()->call([$job, 'handle']);
        $job->assertFailed();
        Http::assertNothingSent();
        $this->assertSame(1, RemotePollVote::count());
    }

    public function test_open_poll_without_deadline_accepts_a_vote(): void
    {
        [$voter, $poll] = $this->votingFixture();
        $poll->update(['ends_at' => null]);
        $vote = app(RemotePollVoter::class)->vote($voter, $poll, [$poll->options[0]->id], $poll->optionsVersion());
        $this->assertSame(1, $vote->choices->count());
    }

    public function test_transactional_queue_misconfiguration_rolls_back_the_vote(): void
    {
        [$voter, $poll] = $this->votingFixture();
        config(['queue.connections.local_delivery.driver' => 'sync']);
        try {
            app(RemotePollVoter::class)->vote($voter, $poll, [$poll->options[0]->id], $poll->optionsVersion());
            $this->fail('Atomic enqueue must require the domain database.');
        } catch (\LogicException) {
            $this->assertSame(0, RemotePollVote::count());
            $this->assertSame(0, DB::table('jobs')->count());
        }
    }

    public function test_visibility_revoked_after_enqueue_stops_delivery(): void
    {
        [$voter, $poll] = $this->votingFixture();
        app(RemotePollVoter::class)->vote($voter, $poll, [$poll->options[0]->id], $poll->optionsVersion());
        $poll->post->update(['visibility' => 'followers']);
        $job = $this->votingJob()->withFakeQueueInteractions();
        app()->call([$job, 'handle']);
        $job->assertFailed();
        Http::assertNothingSent();
        $this->assertSame(1, RemotePollVote::count());
    }

    private function votingJob(): DeliverActivityJob
    {
        return $this->decodeVotingJob(DB::table('jobs')->where('queue', 'delivery')->orderBy('id')->first()->payload);
    }

    private function decodeVotingJob(string $payload): DeliverActivityJob
    {
        return unserialize(json_decode($payload, true, 512, JSON_THROW_ON_ERROR)['data']['command']);
    }

    private function assertVotingValidation(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected voting validation error.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }
}
