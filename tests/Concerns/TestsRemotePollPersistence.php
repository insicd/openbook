<?php

namespace Tests\Concerns;

use App\Application\Services\AccountRegistrar;
use App\Application\Services\DomainBlockManager;
use App\Domain\Polls\Poll;
use App\Domain\Polls\PollOption;
use App\Domain\Polls\PollVote;
use App\Domain\Polls\PollVoteChoice;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Inbox\RemoteNoteUpserter;
use App\Federation\Polls\PollVoteDeliveryGuard;
use App\Federation\Serialization\PollVoteSerializer;
use App\Infrastructure\Security\Http\SafeHttpClient;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Database\QueryException;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;

trait TestsRemotePollPersistence
{
    public function test_poll_relations_casts_and_expiry_use_the_test_clock(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 8)->setTime(10, 0));
        try {
            $poll = $this->newPoll();
            $poll->update(['voters_count' => null, 'ends_at' => now()->addDay()]);
            $poll->options()->create(['position' => 1, 'name' => 'Domenica', 'votes_count' => null]);
            $poll->options()->create(['position' => 0, 'name' => 'Sabato', 'votes_count' => 0]);
            $poll = $poll->fresh();

            $this->assertSame($poll->id, $poll->post->poll->id);
            $this->assertSame(['Sabato', 'Domenica'], $poll->options->pluck('name')->all());
            $this->assertSame(0, $poll->options[0]->votes_count);
            $this->assertNull($poll->options[1]->votes_count);
            $this->assertNull($poll->voters_count);
            $this->assertFalse($poll->multiple);
            $this->assertFalse($poll->isClosed());
            $this->travelTo($poll->ends_at);
            $this->assertTrue($poll->isClosed());
            $poll->update(['ends_at' => null]);
            $this->assertFalse($poll->isClosed());
            $poll->update(['closed_at' => now()->subSecond()]);
            $this->assertTrue($poll->isClosed());
            $poll->update(['closed_at' => null, 'closed' => true]);
            $this->assertTrue($poll->isClosed());
        } finally {
            $this->travelBack();
        }
    }

    public function test_database_enforces_one_poll_per_post_and_one_vote_per_actor(): void
    {
        $poll = $this->newPoll();
        $this->assertUniqueViolation(fn () => Poll::create(['post_id' => $poll->post_id, 'multiple' => true]));
        $actor = $this->pollActor(true);
        $vote = $poll->votes()->create(['actor_id' => $actor->id]);
        $this->assertSame($actor->id, $vote->actor->id);
        $this->assertUniqueViolation(fn () => $poll->votes()->create(['actor_id' => $actor->id]));
        $poll->votes()->create(['actor_id' => $this->pollActor(true)->id]);
        $this->assertSame(2, $poll->votes()->count());
    }

    public function test_expiry_beyond_2038_round_trips_without_timestamp_overflow(): void
    {
        $poll = $this->newPoll();
        $poll->update(['ends_at' => '2040-01-01 12:00:00', 'closed_at' => '2040-01-02 12:00:00']);
        $this->assertSame('2040-01-01 12:00:00', $poll->fresh()->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame('2040-01-02 12:00:00', $poll->fresh()->closed_at->format('Y-m-d H:i:s'));
    }

    public function test_option_identity_is_exact_and_choice_snapshots_survive_option_replacement(): void
    {
        $poll = $this->newPoll();
        $first = $poll->options()->create(['position' => 0, 'name' => 'Sabato']);
        $poll->options()->create(['position' => 1, 'name' => 'sabato']);
        $poll->options()->create(['position' => 2, 'name' => 'Sábato']);
        $this->assertUniqueViolation(fn () => $poll->options()->create(['position' => 3, 'name' => 'Sabato']));
        $this->assertUniqueViolation(fn () => $poll->options()->create(['position' => 0, 'name' => 'Altro']));
        $vote = $poll->votes()->create(['actor_id' => $this->pollActor(true)->id]);
        $choice = $vote->choices()->create(['position' => $first->position, 'name' => $first->name]);
        $this->assertUniqueViolation(fn () => $vote->choices()->create(['position' => 1, 'name' => 'Sabato']));
        $this->assertUniqueViolation(fn () => $vote->choices()->create(['position' => 0, 'name' => 'Altro']));
        $first->update(['name' => 'Venerdì']);
        $first->delete();
        $this->assertSame('Sabato', $choice->fresh()->name);
        $this->assertSame($choice->id, $vote->fresh()->choices->sole()->id);
        $this->assertArrayNotHasKey('votes', $poll->load('votes.choices')->toArray());
        $this->assertArrayNotHasKey('choices', $vote->load('choices')->toArray());
    }

    public function test_deleting_an_actor_or_post_cascades_without_orphan_choices(): void
    {
        $poll = $this->newPoll();
        $poll->options()->create(['position' => 0, 'name' => 'Sabato']);
        $actor = $this->pollActor(true);
        $vote = $poll->votes()->create(['actor_id' => $actor->id]);
        $vote->choices()->create(['position' => 0, 'name' => 'Sabato']);
        $actor->delete();
        $this->assertSame(1, PollVote::count());
        $this->assertSame(1, PollVoteChoice::count());
        $this->assertNull($vote->fresh()->actor_id);
        $vote = $poll->votes()->create(['actor_id' => $this->pollActor(true)->id]);
        $vote->choices()->create(['position' => 0, 'name' => 'Sabato']);
        $poll->post->delete();
        $this->assertSame(0, Poll::count());
        $this->assertSame(0, PollOption::count());
        $this->assertSame(0, PollVote::count());
        $this->assertSame(0, PollVoteChoice::count());
    }

    public function test_database_queue_can_persist_choices_and_jobs_in_one_transaction(): void
    {
        $poll = $this->newPoll(true);
        $actor = $this->pollActor(true);
        $queue = $this->pollDatabaseQueue();
        $vote = DB::transaction(function () use ($poll, $actor, $queue): PollVote {
            $vote = $poll->votes()->create(['actor_id' => $actor->id]);
            foreach (['Sabato', 'Domenica'] as $position => $name) {
                $choice = $vote->choices()->create(['position' => $position, 'name' => $name]);
                $queue->push($this->pollDeliveryJob($choice, $actor), '', 'delivery');
            }
            $this->assertSame(2, DB::table('jobs')->where('queue', 'delivery')->count());

            return $vote;
        });
        $this->assertSame(2, $vote->choices()->count());
        $this->assertSame(2, DB::table('jobs')->count());
        $this->assertSame(0, $poll->fresh()->voters_count);
        foreach (DB::table('jobs')->get() as $row) {
            $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
            $job = unserialize($payload['data']['command']);
            $this->assertInstanceOf(DeliverActivityJob::class, $job);
            $this->assertSame('delivery', $job->queue);
            $this->assertFalse($job->afterCommit);
            $this->assertSame($actor->id, $job->signingActorId);
        }
    }

    public function test_failure_on_second_enqueue_rolls_back_vote_choices_and_first_job(): void
    {
        $poll = $this->newPoll(true);
        $actor = $this->pollActor(true);
        $queue = $this->pollDatabaseQueue();
        $calls = 0;
        Queue::createPayloadUsing(function () use (&$calls): array {
            if (++$calls === 2) {
                throw new RuntimeException('Simulated second enqueue failure');
            }

            return [];
        });
        try {
            DB::transaction(function () use ($poll, $actor, $queue): void {
                $vote = $poll->votes()->create(['actor_id' => $actor->id]);
                foreach (['Sabato', 'Domenica'] as $position => $name) {
                    $choice = $vote->choices()->create(['position' => $position, 'name' => $name]);
                    $queue->push($this->pollDeliveryJob($choice, $actor), '', 'delivery');
                }
            });
            $this->fail('Queue failure did not propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated second enqueue failure', $exception->getMessage());
        } finally {
            Queue::createPayloadUsing(null);
        }
        $this->assertSame(0, PollVote::count());
        $this->assertSame(0, PollVoteChoice::count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_shared_import_preserves_ids_during_option_swaps_on_the_database(): void
    {
        $poll = $this->newPoll();
        $post = $poll->post;
        $note = json_decode(file_get_contents(base_path('tests/Fixtures/Federation/Polls/single-create.json')), true, 512, JSON_THROW_ON_ERROR)['object'];
        $note['id'] = $post->uri;
        $note['attributedTo'] = $post->actor->uri;
        $upserter = app(RemoteNoteUpserter::class);
        $this->assertNotNull($upserter->upsertPost($note, $post->uri, $post->actor, 'Question', now(), notifyMentions: false));
        $ids = $poll->fresh()->options->pluck('id', 'name');
        [$note['oneOf'][0], $note['oneOf'][1]] = [$note['oneOf'][1], $note['oneOf'][0]];
        $note['oneOf'][2]['name'] = 'Venerdì';
        $upserter->upsertPost($note, $post->uri, $post->actor, 'Question', now(), notifyMentions: false);
        $options = $poll->fresh()->options;
        $this->assertSame($ids['Domenica'], $options[0]->id);
        $this->assertSame($ids['Sabato'], $options[1]->id);
        $this->assertSame('Venerdì', $options[2]->name);
        $this->assertNotSame($ids['Lunedì'], $options[2]->id);
    }

    public function test_observation_migrations_can_be_rolled_back_and_reapplied(): void
    {
        $simplification = require base_path('database/migrations/2026_10_15_000002_simplify_remote_poll_timestamps.php');
        $observation = require base_path('database/migrations/2026_10_15_000001_add_observation_to_remote_polls.php');
        $unification = require base_path('database/migrations/2026_10_15_000003_unify_poll_tables.php');
        $unification->down();
        $simplification->down();
        $observation->down();
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasColumn('remote_polls', 'observed_at'));
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasColumn('remote_polls', 'results_received_at'));
        $observation->up();
        $simplification->up();
        $this->assertTrue(DB::connection()->getSchemaBuilder()->hasColumn('remote_polls', 'results_received_at'));
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasColumn('remote_polls', 'observed_at'));
        $unification->up();
    }

    public function test_timestamp_simplification_preserves_results_receipt_and_vote_dates(): void
    {
        $poll = $this->newPoll();
        $poll->forceFill(['results_received_at' => '2026-10-08 10:05:00'])->save();
        $option = $poll->options()->create(['position' => 0, 'name' => 'Sabato']);
        $vote = $poll->votes()->create(['actor_id' => $this->pollActor(true)->id]);
        $choice = $vote->choices()->create(['position' => 0, 'name' => 'Sabato']);
        $migration = require base_path('database/migrations/2026_10_15_000002_simplify_remote_poll_timestamps.php');
        $unification = require base_path('database/migrations/2026_10_15_000003_unify_poll_tables.php');
        $unification->down();
        $migration->down();
        // Simulate a local DB that already ran the earlier sprint migrations.
        DB::table('remote_polls')->where('id', $poll->id)->update(['created_at' => '2026-10-08 10:00:00', 'updated_at' => '2026-10-08 10:06:00']);
        $migration->up();
        $unification->up();
        $schema = DB::connection()->getSchemaBuilder();
        foreach (['polls', 'poll_options'] as $table) {
            $this->assertFalse($schema->hasColumn($table, 'created_at'));
            $this->assertFalse($schema->hasColumn($table, 'updated_at'));
        }
        $this->assertSame('2026-10-08 10:05:00', $poll->fresh()->results_received_at->format('Y-m-d H:i:s'));
        $this->assertSame('Sabato', $option->fresh()->name);
        $poll->update(['voters_count' => 10]);
        $option->update(['votes_count' => 10]);
        $this->assertSame(10, $poll->fresh()->voters_count);
        $this->assertNotNull($vote->fresh()->created_at);
        $this->assertNotNull($choice->fresh()->created_at);
    }

    private function newPoll(bool $multiple = false): Poll
    {
        $post = Post::create(['actor_id' => $this->pollActor()->id, 'uri' => 'https://remote.example/posts/'.Str::uuid(), 'body' => 'Poll', 'visibility' => 'public', 'status' => 'published', 'published_at' => now()]);

        return Poll::create(['post_id' => $post->id, 'multiple' => $multiple, 'voters_count' => 0]);
    }

    public function test_unification_preserves_all_four_tables_and_already_queued_votes(): void
    {
        $poll = $this->newPoll();
        $poll->forceFill(['voters_count' => null, 'remote_updated_at' => '2026-10-08 10:03:00',
            'results_received_at' => '2026-10-08 10:05:00', 'source_item_id' => (string) Str::uuid()])->save();
        $option = $poll->options()->create(['position' => 0, 'name' => 'Sabato 💪', 'votes_count' => null]);
        $voter = app(AccountRegistrar::class)->register([
            'username' => 'migrationvoter', 'email' => 'migrationvoter@example.test', 'password' => 'Password123',
        ])->actor;
        $vote = $poll->votes()->create(['actor_id' => $voter->id]);
        $choice = $vote->choices()->create(['position' => 0, 'name' => $option->name]);
        $activity = PollVoteSerializer::create($choice, $voter, $poll->post);
        $this->pollDatabaseQueue()->push(new DeliverActivityJob('https://remote.example/inbox', $activity, $voter->id, pollVoteChoiceId: $choice->id), '', 'delivery');
        $queued = DB::table('jobs')->value('payload');
        $optionsVersion = $poll->fresh()->load('options')->optionsVersion();
        $migration = require base_path('database/migrations/2026_10_15_000003_unify_poll_tables.php');
        $migration->down();
        $tables = ['remote_polls' => 'polls', 'remote_poll_options' => 'poll_options',
            'remote_poll_votes' => 'poll_votes', 'remote_poll_vote_choices' => 'poll_vote_choices'];
        $before = [];
        foreach ($tables as $old => $new) {
            $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable($old));
            $before[$new] = (array) DB::table($old)->first();
        }
        $migration->up();
        foreach ($tables as $old => $new) {
            $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable($old));
            $row = (array) DB::table($new)->first();
            $this->assertSame($before[$new], array_intersect_key($row, $before[$new]), $new);
        }
        $this->assertSame($queued, DB::table('jobs')->value('payload'));
        $this->assertSame($optionsVersion, $poll->fresh()->load('options')->optionsVersion());
        $this->assertSame($poll->id, $poll->post->fresh()->poll->id);
        $this->assertNull($poll->fresh()->voters_count);
        $this->assertFalse($poll->fresh()->needs_delivery);
        $this->assertFalse($poll->fresh()->publication_pending);
        $this->assertSame(PollVote::identityHash($voter->uri), $vote->fresh()->voter_hash);
        $this->assertTrue(app(PollVoteDeliveryGuard::class)->allows($choice->id, $voter, 'https://remote.example/inbox'));
        Http::fake(['*' => Http::response('', 202)]);
        $job = unserialize(json_decode($queued, true, 512, JSON_THROW_ON_ERROR)['data']['command']);
        $job->handle(app(SafeHttpClient::class), app(DomainBlockManager::class));
        Http::assertSentCount(1);
        $this->assertSame($activity['id'], $job->activity['id']);
        $this->assertSame($activity['object']['id'], $job->activity['object']['id']);
        $this->assertSame($choice->id, $job->pollVoteChoiceId);
    }

    private function pollActor(bool $local = false): Actor
    {
        $name = 'poll'.Str::lower(Str::random(12));

        return Actor::create(['type' => 'person', 'is_local' => $local, 'preferred_username' => $name, 'domain' => $local ? 'openbook.test' : 'remote.example', 'uri' => 'https://'.($local ? 'openbook.test' : 'remote.example').'/users/'.$name, 'status' => 'active']);
    }

    private function assertUniqueViolation(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Duplicate persisted despite unique constraint.');
        } catch (QueryException $exception) {
            $this->assertStringContainsStringIgnoringCase('unique', $exception->getMessage());
        }
    }

    private function pollDatabaseQueue(): DatabaseQueue
    {
        $queue = new DatabaseQueue(DB::connection(), 'jobs', 'delivery', 90, true);
        $queue->setContainer(app());

        return $queue;
    }

    private function pollDeliveryJob(PollVoteChoice $choice, Actor $actor): DeliverActivityJob
    {
        return (new DeliverActivityJob('https://remote.example/inbox', ['id' => $actor->uri.'#votes/'.$choice->id.'/activity', 'type' => 'Create'], $actor->id))->beforeCommit();
    }
}
