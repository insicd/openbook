<?php

namespace Tests\Feature\Polls;

use App\Domain\Polls\Poll;
use App\Domain\Posts\Post;
use App\Federation\Inbox\RemoteNoteUpserter;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TestsRemotePollPersistence;
use Tests\Concerns\TestsRemotePollVoting;
use Tests\Support\PollMysqlTestCase;

/** Opt-in: creates and removes its own random schema, never migrates the configured local DB. */
class RemotePollMysqlPersistenceTest extends PollMysqlTestCase
{
    use TestsRemotePollPersistence, TestsRemotePollVoting;

    public function test_concurrent_votes_commit_only_one_vote_and_one_delivery(): void
    {
        [$voter, $poll] = $this->votingFixture();
        $input = json_encode([
            'database' => config('database.connections.poll_schema'), 'key' => config('app.key'),
            'voter' => $voter->id, 'poll' => $poll->id,
            'choices' => [$poll->options[0]->id], 'version' => $poll->optionsVersion(),
        ], JSON_THROW_ON_ERROR);
        $children = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $process = proc_open([PHP_BINARY, base_path('tests/Support/ConcurrentPollVote.php')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $children[] = [$process, $pipes];
                stream_set_timeout($pipes[1], 20);
                fwrite($pipes[0], $input."\n");
                $this->assertSame("ready\n", fgets($pipes[1]));
            }
            foreach ($children as [$process, $pipes]) {
                fwrite($pipes[0], "go\n");
                fflush($pipes[0]);
            }
            $ids = [];
            foreach ($children as [$process, $pipes]) {
                $ids[] = trim(fgets($pipes[1]) ?: '');
            }
            $this->assertNotSame('', $ids[0]);
            $this->assertSame($ids[0], $ids[1]);
            $this->assertSame(1, $poll->votes()->count());
            $this->assertSame(1, DB::table('jobs')->count());
        } finally {
            foreach ($children as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                proc_terminate($process);
                proc_close($process);
            }
        }
    }

    public function test_new_migration_rolls_back_and_reapplies_on_mysql(): void
    {
        $migration = require base_path('database/migrations/2026_10_15_000003_unify_poll_tables.php');
        $migration->down();
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('polls'));
        $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable('remote_polls'));
        $migration->up();
        $this->newPoll();
        $this->assertSame(1, DB::table('polls')->count());
    }

    public function test_mysql_uses_indexes_for_poll_option_and_personal_vote_queries(): void
    {
        $actor = $this->pollActor(true);
        $target = null;
        for ($i = 0; $i < 100; $i++) {
            $poll = $this->newPoll();
            foreach (['Sabato', 'Domenica', 'Lunedì', 'Altro'] as $position => $name) {
                $poll->options()->create(['position' => $position, 'name' => $name]);
            }
            $vote = $poll->votes()->create(['actor_id' => $actor->id]);
            foreach (['Sabato', 'Domenica'] as $position => $name) {
                $vote->choices()->create(['position' => $position, 'name' => $name]);
            }
            $target ??= $poll;
        }

        $queries = [
            [Post::whereKey($target->post_id)->where('status', Post::STATUS_PUBLISHED)->visibleTo($actor), 'PRIMARY'],
            [Poll::where('post_id', $target->post_id), 'polls_post_id_unique'],
            [$target->options()->getQuery(), 'poll_options_position_unique'],
            [$target->votes()->where('actor_id', $actor->id)->getQuery(), 'poll_votes_actor_unique'],
            [$target->votes()->sole()->choices()->getQuery(), 'poll_vote_choices_position_unique'],
        ];
        foreach ($queries as [$query, $index]) {
            $plan = DB::select('EXPLAIN FORMAT=TRADITIONAL '.$query->toSql(), $query->getBindings());
            $this->assertSame($index, $plan[0]->key, $query->toSql());
            $this->assertStringNotContainsString('filesort', strtolower($plan[0]->Extra ?? ''));
        }

        $observedQueries = [];
        DB::listen(function ($event) use (&$observedQueries): void {
            if (str_contains($event->sql, '`poll_options`')
                && (str_starts_with($event->sql, 'select') || str_starts_with($event->sql, 'delete'))) {
                $observedQueries[] = [$event->sql, $event->bindings];
            }
        });
        $note = json_decode(file_get_contents(base_path('tests/Fixtures/Federation/Polls/single-update.json')), true, 512, JSON_THROW_ON_ERROR)['object'];
        $note['id'] = $target->post->uri;
        $note['attributedTo'] = $target->post->actor->uri;
        app(RemoteNoteUpserter::class)->upsertPost($note, $note['id'], $target->post->actor, 'Question', now(), notifyMentions: false);
        $this->assertNotEmpty($observedQueries);
        foreach ($observedQueries as [$sql, $bindings]) {
            $plan = DB::select('EXPLAIN FORMAT=TRADITIONAL '.$sql, $bindings);
            $this->assertContains($plan[0]->key, ['poll_options_position_unique', 'poll_options_name_unique'], $sql);
            $this->assertStringNotContainsString('filesort', strtolower($plan[0]->Extra ?? ''));
        }

        $page = Poll::query()->limit(5)->get();
        $presentationQueries = [];
        DB::listen(function ($event) use (&$presentationQueries): void {
            if (str_starts_with($event->sql, 'select') && (str_contains($event->sql, '`posts`')
                || str_contains($event->sql, '`poll_votes`') || str_contains($event->sql, '`poll_vote_choices`'))) {
                $presentationQueries[] = [$event->sql, $event->bindings];
            }
        });
        Poll::annotateViewerState($page, $actor);
        $this->assertCount(3, $presentationQueries);
        foreach ($presentationQueries as [$sql, $bindings]) {
            $plan = DB::select('EXPLAIN FORMAT=TRADITIONAL '.$sql, $bindings);
            $this->assertNotNull($plan[0]->key, $sql);
            $this->assertNotSame('ALL', $plan[0]->type, $sql);
        }

    }
}
