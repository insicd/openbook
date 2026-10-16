<?php

namespace Tests\Concerns;

use App\Application\Services\CommunityRegistrar;
use App\Application\Services\DomainBlockManager;
use App\Application\Services\PostComposer;
use App\Domain\Polls\LocalPollDefinition;
use App\Domain\Polls\Poll;
use App\Domain\Polls\PollOption;
use App\Domain\Polls\PollVote;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Actors\RemoteActorDeletionService;
use App\Federation\Polls\RemotePollDocument;
use App\Federation\Serialization\ActivitySerializer;
use App\Federation\Serialization\NoteSerializer;
use App\Infrastructure\Security\Http\SafeHttpClient;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

trait TestsLocalPollFoundations
{
    use CreatesAccounts, CreatesRemoteActors;

    private function localPollPost(array $overrides = []): Post
    {
        $author = $this->createFullAccount('pollauthor');
        $input = json_decode(file_get_contents(base_path('tests/Fixtures/Federation/Polls/local-post-with-poll.json')), true, 512, JSON_THROW_ON_ERROR);

        return app(PostComposer::class)->compose($author->actor, array_replace($input, $overrides));
    }

    public function test_poll_is_created_with_zero_results_and_durable_pending_work(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));
        $post = $this->localPollPost();
        $poll = $post->fresh()->poll;
        $this->assertSame(['Sabato', 'Domenica'], $poll->options->pluck('name')->all());
        $this->assertSame([0, 0], $poll->options->pluck('votes_count')->all());
        $this->assertSame(0, $poll->voters_count);
        $this->assertFalse($poll->multiple);
        $this->assertTrue($poll->ends_at->equalTo($post->published_at->copy()->addDay()));
        $this->assertTrue($poll->needs_delivery);
        $this->assertTrue($poll->publication_pending);
        $this->assertSame(1, $poll->revision);
        $this->assertSame(0, $poll->distributed_revision);
        $this->assertSame($poll->id, $post->poll->id);
        $this->assertFalse($poll->isClosed());
        $this->travelTo($poll->ends_at);
        $this->assertTrue($poll->isClosed());
    }

    public function test_question_has_the_same_uri_and_audience_in_create_and_canonical_document(): void
    {
        $post = $this->localPollPost(['visibility' => Post::VISIBILITY_FOLLOWERS]);
        $note = NoteSerializer::forPost($post);
        $create = ActivitySerializer::create($post);
        $this->assertSame('Question', $note['type']);
        $this->assertSame(NoteSerializer::postUri($post), $note['id']);
        $this->assertSame($note, $create['object']);
        $this->assertSame($note['to'], $create['to']);
        $this->assertSame([], $note['cc']);
        $this->assertNotContains(NoteSerializer::PUBLIC_STREAM, $note['to']);
        $this->assertSame(0, $note['votersCount']);
        $this->assertSame(0, $note['oneOf'][0]['replies']['totalItems']);
        $this->assertArrayNotHasKey('anyOf', $note);
        $this->assertArrayNotHasKey('closed', $note);
        $this->assertArrayNotHasKey('updated', $note);
        $this->assertSame($post->published_at->copy()->addDay()->toAtomString(), $note['endTime']);
        $this->assertContains(['votersCount' => 'http://joinmastodon.org/ns#votersCount'], $note['@context']);
        $imported = RemotePollDocument::fromArray($note);
        $this->assertSame(['Sabato', 'Domenica'], array_column($imported->options, 'name'));
        $this->assertSame(0, $imported->votersCount);
        $this->travelTo($post->poll->ends_at);
        $this->assertSame($note['endTime'], NoteSerializer::forPost($post)['closed']);
        $this->assertNull($post->poll->closed_at);
    }

    public function test_six_unicode_options_and_multiple_choice_are_supported(): void
    {
        $options = ['Sì 💪', 'Si', 'si', str_repeat('è', 50), 'Giovedì', 'Altro'];
        $post = $this->localPollPost(['poll' => ['options' => $options, 'multiple' => true, 'duration_seconds' => 300]]);
        $note = NoteSerializer::forPost($post);
        $this->assertSame($options, array_column($note['anyOf'], 'name'));
        $this->assertArrayNotHasKey('oneOf', $note);
        $this->assertTrue($post->poll->multiple);
    }

    #[DataProvider('invalidLocalPollDefinitions')]
    public function test_invalid_definitions_do_not_create_partial_posts(array $poll): void
    {
        try {
            $this->localPollPost(['poll' => $poll]);
            $this->fail('Invalid poll accepted.');
        } catch (ValidationException) {
            $this->assertSame(0, Post::query()->count());
            $this->assertSame(0, Poll::query()->count());
        }
    }

    public static function invalidLocalPollDefinitions(): array
    {
        $valid = ['options' => ['Sabato', 'Domenica'], 'multiple' => false, 'duration_seconds' => 86400];

        return [
            'one option' => [array_replace($valid, ['options' => ['Sabato']])],
            'seven options' => [array_replace($valid, ['options' => range('a', 'g')])],
            'long Unicode option' => [array_replace($valid, ['options' => [str_repeat('è', 51), 'Altro']])],
            'empty trimmed option' => [array_replace($valid, ['options' => ['  ', 'Altro']])],
            'duplicate after trim' => [array_replace($valid, ['options' => [' Sabato', 'Sabato ']])],
            'duration too short' => [array_replace($valid, ['duration_seconds' => 299])],
            'duration too long' => [array_replace($valid, ['duration_seconds' => 604801])],
            'invented expiry' => [array_replace($valid, ['ends_at' => '2040-01-01T00:00:00Z'])],
        ];
    }

    public function test_creation_rolls_back_post_options_and_pending_work_together(): void
    {
        $author = $this->createFullAccount('rollbackauthor');
        try {
            DB::transaction(function () use ($author): void {
                app(PostComposer::class)->compose($author->actor, [
                    'body' => 'Rollback', 'poll' => LocalPollDefinition::fromInput([
                        'options' => ['Uno', 'Due'], 'multiple' => false, 'duration_seconds' => 300,
                    ])->toArray(),
                ]);
                throw new RuntimeException('Simulated failure after creation.');
            });
            $this->fail('Failure expected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated failure after creation.', $exception->getMessage());
        }
        $this->assertSame(0, Post::query()->count());
        $this->assertSame(0, Poll::query()->count());
        $this->assertSame(0, PollOption::query()->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_direct_posts_and_addressed_remote_groups_are_rejected(): void
    {
        $author = $this->createFullAccount('limitedauthor');
        $group = $this->createRemoteActor('remotecommunity', overrides: ['type' => Actor::TYPE_GROUP]);
        foreach ([['visibility' => 'direct'], ['addressed_group_actor_id' => $group->id], ['body' => '@remotecommunity@remoto.example']] as $data) {
            try {
                app(PostComposer::class)->compose($author->actor, array_replace([
                    'body' => 'Question', 'poll' => ['options' => ['Uno', 'Due'], 'multiple' => false, 'duration_seconds' => 300],
                ], $data));
                $this->fail('Unsupported destination accepted.');
            } catch (ValidationException) {
                $this->assertSame(0, Poll::query()->count());
            }
        }
    }

    public function test_poll_updates_preserve_definition_and_record_a_new_revision(): void
    {
        $post = $this->localPollPost();
        $before = NoteSerializer::forPost($post);
        $this->travel(2)->minutes();
        $updated = app(PostComposer::class)->update($post->actor, $post, ['body' => 'Testo corretto', 'visibility' => 'public']);
        $after = NoteSerializer::forPost($updated);
        $this->assertSame($before['oneOf'], $after['oneOf']);
        $this->assertSame($before['endTime'], $after['endTime']);
        $this->assertSame($before['id'], $after['id']);
        $this->assertSame(2, $updated->poll->revision);
        $this->assertSame($updated->edited_at->toAtomString(), $after['updated']);
        $this->assertStringContainsString('Testo corretto', $after['content']);
        foreach ([['poll' => null], ['visibility' => 'followers']] as $data) {
            try {
                app(PostComposer::class)->update($post->actor, $updated, array_replace(['body' => 'Forbidden'], $data));
                $this->fail('Immutable poll changed.');
            } catch (ValidationException) {
                $this->assertSame(2, $updated->poll->fresh()->revision);
                $this->assertSame('Testo corretto', $updated->fresh()->body);
            }
        }
    }

    public function test_poll_model_prevents_definition_changes(): void
    {
        $poll = $this->localPollPost()->poll;
        try {
            $poll->update(['ends_at' => now()->addYear()]);
            $this->fail('Expiry changed.');
        } catch (ValidationException) {
            $this->assertTrue($poll->fresh()->ends_at->lessThan(now()->addDays(2)));
        }
        try {
            $poll->options->first()->update(['name' => 'Renamed']);
            $this->fail('Option renamed.');
        } catch (ValidationException) {
            $this->assertSame('Sabato', $poll->options->first()->fresh()->name);
        }
    }

    public function test_option_identity_uniqueness_is_independent_of_database_collation(): void
    {
        $poll = $this->localPollPost()->poll;
        $poll->options()->create(['position' => 2, 'name' => 'sabato']);
        try {
            DB::transaction(fn () => $poll->options()->create(['position' => 3, 'name' => 'Sabato']));
            $this->fail('Duplicate option accepted.');
        } catch (QueryException) {
            $this->assertSame(3, $poll->options()->count());
        }
    }

    public function test_actor_deletion_preserves_votes_counts_and_replay_identity(): void
    {
        $poll = $this->localPollPost()->poll;
        $voter = $this->createRemoteActor('voter');
        $hash = PollVote::identityHash($voter->uri);
        $vote = $poll->votes()->create(['actor_id' => $voter->id, 'voter_hash' => $hash]);
        $choice = $vote->choices()->create(['option_id' => $poll->options->first()->id, 'remote_uri_hash' => PollVote::identityHash($voter->uri.'#vote/1')]);
        $poll->forceFill(['voters_count' => 1])->save();
        $poll->options->first()->forceFill(['votes_count' => 1])->save();
        app(RemoteActorDeletionService::class)->delete($voter);
        $this->assertNull($vote->fresh()->actor_id);
        $this->assertSame($hash, $vote->fresh()->voter_hash);
        $this->assertNotNull($choice->fresh());
        $this->assertSame(1, $poll->fresh()->voters_count);
        $this->assertSame(1, $poll->options->first()->fresh()->votes_count);
        try {
            DB::transaction(fn () => $poll->votes()->create(['actor_id' => null, 'voter_hash' => $hash]));
            $this->fail('Replay accepted.');
        } catch (QueryException) {
            $this->assertSame(1, $poll->votes()->count());
        }
        $this->assertArrayNotHasKey('votes', $poll->load('votes.choices')->toArray());
        $this->assertArrayNotHasKey('voter_hash', $vote->fresh()->toArray());
    }

    public function test_hard_actor_and_user_deletion_detach_votes_without_removing_choices(): void
    {
        $poll = $this->localPollPost()->poll;
        foreach ([false, true] as $deleteUser) {
            $user = $this->createFullAccount($deleteUser ? 'deleteuser' : 'deleteactor');
            $vote = $poll->votes()->create(['actor_id' => $user->actor->id, 'voter_hash' => PollVote::identityHash($user->actor->uri)]);
            $vote->choices()->create(['option_id' => $poll->options->first()->id]);
            if ($deleteUser) {
                $user->delete();
            } else {
                DB::table('actors')->where('id', $user->actor->id)->delete();
            }
            $this->assertNull($vote->fresh()->actor_id);
            $this->assertSame(1, $vote->choices()->count());
        }
        $this->assertSame(2, $poll->votes()->count());
    }

    public function test_suspension_and_block_preserve_existing_vote_details(): void
    {
        $poll = $this->localPollPost()->poll;
        $actor = $this->createRemoteActor('moderated');
        $vote = $poll->votes()->create(['actor_id' => $actor->id, 'voter_hash' => PollVote::identityHash($actor->uri)]);
        foreach ([Actor::STATUS_SUSPENDED, Actor::STATUS_BLOCKED] as $status) {
            $actor->update(['status' => $status]);
            $this->assertSame($actor->id, $vote->fresh()->actor_id);
        }
    }

    public function test_logical_post_deletion_removes_choices_and_stops_pending_work(): void
    {
        $post = $this->localPollPost();
        $poll = $post->poll;
        $vote = $poll->votes()->create(['actor_id' => $post->actor_id, 'voter_hash' => PollVote::identityHash($post->actor->uri)]);
        $vote->choices()->create(['option_id' => $poll->options->first()->id]);
        $post->update(['status' => Post::STATUS_DELETED, 'body' => '']);
        $this->assertSame(0, $poll->votes()->count());
        $this->assertSame(0, $poll->options()->count());
        $this->assertFalse($poll->fresh()->needs_delivery);
        $this->assertFalse($poll->fresh()->publication_pending);
        $this->assertSame('Question', NoteSerializer::tombstoneForPost($post)['formerType']);
        $post->delete();
        $this->assertNull($poll->fresh());
    }

    public function test_private_community_question_keeps_the_group_audience(): void
    {
        $owner = $this->createFullAccount('communityauthor');
        $community = app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'privatepolls', 'name' => 'Private polls', 'is_private' => true,
        ]);
        $post = app(PostComposer::class)->compose($owner->actor, [
            'body' => 'Question', 'visibility' => 'public', 'community_id' => $community->id,
            'poll' => ['options' => ['Uno', 'Due'], 'multiple' => false, 'duration_seconds' => 300],
        ]);
        $note = NoteSerializer::forPost($post);
        $this->assertSame('Question', $note['type']);
        $this->assertSame([], $note['cc']);
        $this->assertNotContains(NoteSerializer::PUBLIC_STREAM, $note['to']);
        $this->assertContains($community->actor->activityPubId(), $note['to']);
        $this->assertNotContains($owner->actor->uri.'/followers', $note['to']);
    }

    public function test_rollback_refuses_to_discard_local_polls(): void
    {
        $post = $this->localPollPost();
        $migration = require base_path('database/migrations/2026_10_15_000003_unify_poll_tables.php');
        try {
            $migration->down();
            $this->fail('Unsafe rollback accepted.');
        } catch (RuntimeException) {
            $this->assertSame($post->poll->id, $post->fresh()->poll->id);
            $this->assertSame(2, $post->poll->options()->count());
        }
    }

    public function test_text_corrections_cannot_add_new_recipients(): void
    {
        $post = $this->localPollPost();
        $this->createFullAccount('newrecipient');
        try {
            app(PostComposer::class)->update($post->actor, $post, ['body' => 'Ciao @newrecipient']);
            $this->fail('Poll audience expanded.');
        } catch (ValidationException) {
            $this->assertSame('Quando ci vediamo?', $post->fresh()->body);
            $this->assertSame(0, $post->mentions()->count());
            $this->assertSame(1, $post->poll->fresh()->revision);
        }
    }

    public function test_queued_question_is_not_delivered_after_post_deletion(): void
    {
        $post = $this->localPollPost();
        $job = new DeliverActivityJob('https://remote.example/inbox', ActivitySerializer::create($post), $post->actor_id);
        Http::fake(['*' => Http::response('', 202)]);
        $job->handle(app(SafeHttpClient::class), app(DomainBlockManager::class));
        Http::assertSentCount(1);
        $author = $post->actor->user;
        $author->update(['status' => 'suspended']);
        Http::fake(['*' => Http::response('', 202)]);
        $job->handle(app(SafeHttpClient::class), app(DomainBlockManager::class));
        Http::assertNothingSent();
        $author->update(['status' => 'active']);
        $post->update(['status' => Post::STATUS_DELETED]);
        URL::forceRootUrl('https://changed.example');
        Http::fake(['*' => Http::response('', 202)]);
        try {
            $job->handle(app(SafeHttpClient::class), app(DomainBlockManager::class));
            Http::assertNothingSent();
        } finally {
            URL::forceRootUrl(null);
        }
    }
}
