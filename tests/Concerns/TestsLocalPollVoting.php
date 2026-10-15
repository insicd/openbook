<?php

namespace Tests\Concerns;

use App\Application\Services\CommunityRegistrar;
use App\Application\Services\DomainBlockManager;
use App\Application\Services\LocalPollVoter;
use App\Application\Services\PostComposer;
use App\Domain\Polls\Poll;
use App\Domain\Polls\PollVote;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Serialization\NoteSerializer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use RuntimeException;

trait TestsLocalPollVoting
{
    use CreatesAccounts, CreatesRemoteActors;

    private function votingFixture(bool $multiple = false, string $visibility = 'public'): array
    {
        Queue::fake();
        Http::preventStrayRequests();
        $author = $this->createFullAccount('author')->actor;
        $voter = $this->createFullAccount('voter')->actor;
        $post = app(PostComposer::class)->compose($author, [
            'body' => 'Quando ci vediamo?', 'visibility' => $visibility,
            'poll' => ['options' => ['Sabato', 'Domenica', 'Altro 💪'], 'multiple' => $multiple, 'duration_seconds' => 300],
        ]);

        return [$voter, $post->poll];
    }

    private function castLocal(Actor $voter, Poll $poll, ?array $choices = null): PollVote
    {
        return app(LocalPollVoter::class)->vote($voter, $poll, $choices ?? [$poll->options[0]->id], $poll->optionsVersion());
    }

    private function castRemote(Actor $voter, Poll $poll, string $name = 'Sabato', string $id = 'one', ?Carbon $received = null): ?PollVote
    {
        return app(LocalPollVoter::class)->receive($voter, $poll, $name, $voter->uri.'#votes/'.$id, $received ?? now());
    }

    public function test_local_vote_updates_authoritative_counts_revision_and_pending_work(): void
    {
        [$voter, $poll] = $this->votingFixture();
        $vote = $this->castLocal($voter, $poll);
        $this->assertSame($voter->id, $vote->actor_id);
        $this->assertSame(['Sabato'], $vote->choices->pluck('name')->all());
        $this->assertSame($poll->options[0]->id, $vote->choices->sole()->option_id);
        $this->assertSame(1, $poll->fresh()->voters_count);
        $this->assertSame([1, 0, 0], $poll->fresh()->options->pluck('votes_count')->all());
        $this->assertSame(2, $poll->fresh()->revision);
        $this->assertTrue($poll->fresh()->needs_delivery);
        $this->assertTrue($poll->fresh()->results_updated_at->equalTo(now()->startOfSecond()));
        $document = NoteSerializer::forPost($poll->post->fresh());
        $this->assertSame(1, $document['votersCount']);
        $this->assertSame(1, $document['oneOf'][0]['replies']['totalItems']);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_local_multiple_selection_is_final_and_counts_one_voter(): void
    {
        [$voter, $poll] = $this->votingFixture(true);
        $first = $this->castLocal($voter, $poll, [$poll->options[0]->id, $poll->options[2]->id]);
        $this->travelTo($poll->ends_at->copy()->addMinute());
        $again = $this->castLocal($voter, $poll, [$poll->options[1]->id]);
        $this->assertSame($first->id, $again->id);
        $this->assertSame([1, 0, 1], $poll->fresh()->options->pluck('votes_count')->all());
        $this->assertSame(1, $poll->fresh()->voters_count);
        $this->assertSame(2, $poll->fresh()->revision);
        $this->assertCount(2, $again->choices);
        Poll::annotateViewerState([$poll], $voter);
        $this->assertSame($first->id, $poll->getRelation('viewerVote')->id);
    }

    public function test_remote_multiple_notes_are_added_once_even_out_of_order(): void
    {
        [, $poll] = $this->votingFixture(true);
        $voter = $this->createRemoteActor('remote');
        $first = $this->castRemote($voter, $poll, 'Altro 💪', 'third');
        $this->castRemote($voter, $poll, 'Sabato', 'first');
        $duplicate = $this->castRemote($voter, $poll, 'Sabato', 'retry-new-id');
        $this->castRemote($voter, $poll, 'Altro 💪', 'third');
        $this->assertSame($first->id, $duplicate->id);
        $this->assertSame(1, $poll->fresh()->voters_count);
        $this->assertSame([1, 0, 1], $poll->fresh()->options->pluck('votes_count')->all());
        $this->assertSame(3, $poll->fresh()->revision);
        $this->assertCount(2, $first->fresh()->choices);
    }

    public function test_remote_single_choice_keeps_first_processed_valid_vote(): void
    {
        [, $poll] = $this->votingFixture();
        $voter = $this->createRemoteActor('remote');
        $this->assertNull($this->castRemote($voter, $poll, ' Sabato', 'invalid'));
        $this->castRemote($voter, $poll, 'Domenica', 'first');
        $this->assertNull($this->castRemote($voter, $poll, 'Sabato', 'second'));
        $this->assertSame([0, 1, 0], $poll->fresh()->options->pluck('votes_count')->all());
        $this->assertSame(1, $poll->fresh()->voters_count);
    }

    public function test_note_identity_cannot_be_reused_for_a_different_option_or_poll(): void
    {
        [, $poll] = $this->votingFixture(true);
        $voter = $this->createRemoteActor('remote');
        $this->castRemote($voter, $poll);
        $this->assertNull($this->castRemote($voter, $poll, 'Domenica'));
        $other = app(PostComposer::class)->compose($poll->post->actor, [
            'body' => 'Other', 'poll' => ['options' => ['Sabato', 'Domenica'], 'multiple' => true, 'duration_seconds' => 300],
        ])->poll;
        $this->assertNull($this->castRemote($voter, $other));
        $this->assertSame(0, $other->fresh()->voters_count);
        $this->assertSame(1, PollVote::count());
    }

    public function test_local_deadline_is_checked_at_confirmation(): void
    {
        [$voter, $poll] = $this->votingFixture();
        $this->travelTo($poll->ends_at);
        $this->assertVoteRejected(fn () => $this->castLocal($voter, $poll));
        $this->assertSame(0, PollVote::count());
    }

    public function test_timely_remote_vote_is_accepted_after_closure_without_reopening(): void
    {
        [, $poll] = $this->votingFixture(true);
        $voter = $this->createRemoteActor('remote');
        $received = $poll->ends_at->copy()->subSecond();
        $this->travelTo($poll->ends_at->copy()->addHour());
        $poll->forceFill(['closed' => true, 'closed_at' => $poll->ends_at])->save();
        $vote = $this->castRemote($voter, $poll, 'Sabato', 'queued', $received);
        $this->assertTrue($vote->created_at->equalTo($received));
        $this->assertTrue($vote->choices->sole()->created_at->equalTo($received));
        $this->assertTrue($poll->fresh()->closed);
        $this->assertTrue($poll->fresh()->closed_at->equalTo($poll->ends_at));
        $this->assertSame(1, $poll->fresh()->voters_count);
        $this->assertTrue($poll->fresh()->needs_delivery);
    }

    public function test_remote_deadline_boundary_and_future_receipts_are_rejected(): void
    {
        [, $poll] = $this->votingFixture();
        $voter = $this->createRemoteActor('remote');
        $this->assertVoteRejected(fn () => $this->castRemote($voter, $poll, received: now()->addSecond()));
        $this->travelTo($poll->ends_at->copy()->addMinute());
        $this->assertVoteRejected(fn () => $this->castRemote($voter, $poll, received: $poll->ends_at));
        $this->assertVoteRejected(fn () => $this->castRemote($voter, $poll, received: $poll->post->published_at->copy()->subSecond()));
        $this->assertSame(0, PollVote::count());
    }

    public function test_invalid_or_stale_local_choices_never_partially_commit(): void
    {
        [$voter, $poll] = $this->votingFixture();
        foreach ([[], ['invented'], [$poll->options[0]->id, $poll->options[0]->id], $poll->options->modelKeys(), [42]] as $choices) {
            $this->assertVoteRejected(fn () => $this->castLocal($voter, $poll, $choices));
        }
        $this->assertVoteRejected(fn () => app(LocalPollVoter::class)->vote($voter, $poll, [$poll->options[0]->id], 'stale'));
        $this->assertSame(0, PollVote::count());
        $this->assertSame([0, 0, 0], $poll->fresh()->options->pluck('votes_count')->all());
        $this->assertSame(1, $poll->fresh()->revision);
    }

    public function test_failed_transaction_rolls_back_choices_counts_and_pending_revision(): void
    {
        [$voter, $poll] = $this->votingFixture(true);
        $poll->forceFill(['needs_delivery' => false])->save();
        try {
            DB::transaction(function () use ($voter, $poll): void {
                $this->castLocal($voter, $poll, $poll->options->modelKeys());
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }
        $this->assertSame(0, PollVote::count());
        $this->assertSame([0, 0, 0], $poll->fresh()->options->pluck('votes_count')->all());
        $this->assertSame(1, $poll->fresh()->revision);
        $this->assertFalse($poll->fresh()->needs_delivery);
    }

    public function test_followers_only_permission_is_rechecked_for_queued_remote_votes(): void
    {
        [, $poll] = $this->votingFixture(visibility: 'followers');
        $voter = $this->createRemoteActor('remote');
        $this->assertVoteRejected(fn () => $this->castRemote($voter, $poll));
        $follow = Follow::create(['follower_id' => $voter->id, 'following_id' => $poll->post->actor_id,
            'status' => 'accepted', 'requested_at' => now(), 'accepted_at' => now()]);
        $this->castRemote($voter, $poll);
        $follow->delete();
        $this->assertVoteRejected(fn () => $this->castRemote($voter, $poll));
        $this->assertSame(1, $poll->fresh()->voters_count);
    }

    public function test_suspended_and_deleted_voters_cannot_add_new_choices(): void
    {
        [$voter, $poll] = $this->votingFixture(true);
        $this->castLocal($voter, $poll);
        $voter->forceFill(['status' => Actor::STATUS_SUSPENDED])->save();
        $this->assertVoteRejected(fn () => $this->castLocal($voter, $poll));
        $remote = $this->createRemoteActor('remote');
        $this->castRemote($remote, $poll);
        $remote->forceFill(['remote_suspended' => true])->save();
        $this->assertVoteRejected(fn () => $this->castRemote($remote, $poll, 'Domenica', 'two'));
        $remote->forceFill(['remote_suspended' => false, 'status' => Actor::STATUS_DELETED])->save();
        $this->assertVoteRejected(fn () => $this->castRemote($remote, $poll, 'Domenica', 'two'));
        $this->assertSame([2, 0, 0], $poll->fresh()->options->pluck('votes_count')->all());
    }

    public function test_blocked_domain_and_suspended_author_prevent_votes(): void
    {
        [, $poll] = $this->votingFixture();
        $remote = $this->createRemoteActor('remote');
        $admin = $poll->post->actor->user;
        $admin->forceFill(['is_admin' => true])->save();
        app(DomainBlockManager::class)->block($admin, 'remoto.example');
        $this->assertVoteRejected(fn () => $this->castRemote($remote, $poll));
        $other = $this->createFullAccount('other')->actor;
        $poll->post->actor->forceFill(['status' => Actor::STATUS_SUSPENDED])->save();
        $this->assertVoteRejected(fn () => $this->castLocal($other, $poll));
        $this->assertSame(0, PollVote::count());
    }

    public function test_author_can_vote_but_a_removed_post_cannot_receive_votes(): void
    {
        [$voter, $poll] = $this->votingFixture();
        $this->castLocal($poll->post->actor, $poll);
        $this->assertSame(1, $poll->fresh()->voters_count);
        DB::transaction(fn () => $poll->post->update(['status' => Post::STATUS_DELETED]));
        $this->assertVoteRejected(fn () => $this->castLocal($voter, $poll));
    }

    public function test_detached_identity_hash_prevents_a_second_ballot_after_actor_deletion(): void
    {
        [, $poll] = $this->votingFixture(true);
        $remote = $this->createRemoteActor('remote');
        $this->castRemote($remote, $poll);
        $uri = $remote->uri;
        $remote->delete();
        $this->assertNull(PollVote::sole()->actor_id);
        $replacement = $this->createRemoteActor('remote', overrides: ['uri' => $uri]);
        $this->castRemote($replacement, $poll, 'Domenica', 'two');
        $this->assertSame(1, $poll->fresh()->voters_count);
        $this->assertSame(1, PollVote::count());
        $this->assertNull(PollVote::sole()->actor_id);
        $this->assertSame([1, 1, 0], $poll->fresh()->options->pluck('votes_count')->all());
    }

    public function test_private_community_membership_is_required_for_local_and_federated_voters(): void
    {
        [$voter, $poll] = $this->votingFixture(true);
        $owner = $poll->post->actor->user;
        $community = app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'privatepolls', 'name' => 'Private polls', 'is_private' => true,
        ]);
        $post = app(PostComposer::class)->compose($owner->actor, [
            'body' => 'Private Question', 'community_id' => $community->id,
            'poll' => ['options' => ['Sabato', 'Domenica'], 'multiple' => true, 'duration_seconds' => 300],
        ]);
        $remote = $this->createRemoteActor('remote');
        $this->assertVoteRejected(fn () => $this->castLocal($voter, $post->poll));
        $this->assertVoteRejected(fn () => $this->castRemote($remote, $post->poll));
        foreach ([$voter, $remote] as $member) {
            Follow::create(['follower_id' => $member->id, 'following_id' => $community->actor_id,
                'status' => 'accepted', 'requested_at' => now(), 'accepted_at' => now()]);
        }
        $this->castLocal($voter, $post->poll);
        $this->castRemote($remote, $post->poll);
        Follow::query()->where('follower_id', $remote->id)->delete();
        $this->assertVoteRejected(fn () => $this->castRemote($remote, $post->poll, 'Domenica', 'two'));
        $this->assertSame(2, $post->poll->fresh()->voters_count);
        $this->assertSame([2, 0], $post->poll->fresh()->options->pluck('votes_count')->all());
    }

    public function test_inactive_local_user_cannot_vote_even_if_actor_is_active(): void
    {
        [$voter, $poll] = $this->votingFixture();
        $voter->user->forceFill(['status' => 'disabled'])->save();
        $this->assertVoteRejected(fn () => $this->castLocal($voter, $poll));
        $this->assertSame(0, PollVote::count());
    }

    private function assertVoteRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Vote should be rejected.');
        } catch (AuthorizationException|ModelNotFoundException|ValidationException) {
            $this->assertTrue(true);
        }
    }
}
