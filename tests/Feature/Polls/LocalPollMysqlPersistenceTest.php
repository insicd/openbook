<?php

namespace Tests\Feature\Polls;

use App\Domain\Polls\Poll;
use App\Domain\Polls\PollOption;
use App\Domain\Polls\PollVote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\TestsLocalPollFoundations;
use Tests\Support\PollMysqlTestCase;

class LocalPollMysqlPersistenceTest extends PollMysqlTestCase
{
    use TestsLocalPollFoundations;

    public function test_poll_migration_rolls_back_and_reapplies_on_mysql(): void
    {
        $migration = require base_path('database/migrations/2026_10_15_000003_unify_poll_tables.php');
        $migration->down();
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('polls'));
        $migration->up();
        $this->localPollPost();
        $this->assertSame(1, Poll::query()->count());
    }

    public function test_mysql_query_plans_use_indexes_for_poll_work_and_cleanup(): void
    {
        $author = $this->createFullAccount('queryauthor')->actor;
        $posts = $polls = $options = $votes = $choices = [];
        $target = null;
        for ($i = 0; $i < 1000; $i++) {
            $postId = (string) Str::uuid();
            $pollId = (string) Str::uuid();
            $optionId = (string) Str::uuid();
            $voteId = (string) Str::uuid();
            $posts[] = ['id' => $postId, 'actor_id' => $author->id, 'body' => 'Question',
                'visibility' => 'public', 'status' => 'published', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()];
            $polls[] = ['id' => $pollId, 'post_id' => $postId, 'multiple' => false,
                'ends_at' => $i < 5 ? now()->subMinute() : now()->addDay(), 'results_updated_at' => now(),
                'needs_delivery' => $i < 5];
            $options[] = ['id' => $optionId, 'poll_id' => $pollId, 'position' => 0, 'name' => 'Sabato', 'name_hash' => hash('sha256', 'Sabato')];
            $votes[] = ['id' => $voteId, 'poll_id' => $pollId, 'actor_id' => $i < 5 ? $author->id : null,
                'voter_hash' => PollVote::identityHash('https://voter.example/'.$i), 'created_at' => now()];
            $choices[] = ['id' => (string) Str::uuid(), 'vote_id' => $voteId, 'option_id' => $optionId,
                'position' => 0, 'name' => 'Sabato', 'name_hash' => hash('sha256', 'Sabato'), 'created_at' => now()];
            if ($i === 0) {
                $target = [$postId, $pollId, $optionId];
            }
        }
        foreach (['posts' => $posts, 'polls' => $polls, 'poll_options' => $options,
            'poll_votes' => $votes, 'poll_vote_choices' => $choices] as $table => $rows) {
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
        $queries = [
            Poll::query()->where('post_id', $target[0]),
            PollOption::query()->where('poll_id', $target[1])->orderBy('position'),
            PollVote::query()->where('poll_id', $target[1])->where('actor_id', $author->id),
            PollVote::query()->where('actor_id', $author->id),
            Poll::query()->where('needs_delivery', true)->orderBy('id')->limit(20),
            Poll::query()->whereNull('closed_at')->where('ends_at', '<=', now())->orderBy('ends_at')->orderBy('id')->limit(20),
            DB::table('poll_vote_choices')->where('option_id', $target[2])->orderBy('id')->limit(20),
        ];
        foreach ($queries as $query) {
            $plan = DB::select('EXPLAIN FORMAT=TRADITIONAL '.$query->toSql(), $query->getBindings());
            $this->assertNotNull($plan[0]->key, $query->toSql());
            $this->assertNotSame('ALL', $plan[0]->type, $query->toSql());
            $this->assertNotSame('PRIMARY', $plan[0]->key, $query->toSql());
            if (str_contains($query->toSql(), 'needs_delivery')) {
                $this->assertSame('polls_delivery_index', $plan[0]->key);
            }
            if (str_contains($query->toSql(), 'closed_at')) {
                $this->assertSame('polls_expiry_index', $plan[0]->key);
            }
        }
        foreach ([
            ['UPDATE poll_votes SET actor_id = NULL WHERE actor_id = ?', $author->id],
            ['DELETE FROM poll_votes WHERE poll_id = ?', $target[1]],
            ['DELETE FROM poll_options WHERE poll_id = ?', $target[1]],
        ] as [$sql, $id]) {
            $plan = DB::select('EXPLAIN FORMAT=TRADITIONAL '.$sql, [$id]);
            $this->assertNotNull($plan[0]->key, $sql);
            $this->assertNotSame('ALL', $plan[0]->type, $sql);
        }
        $backfill = DB::table('poll_votes')->join('actors', 'actors.id', '=', 'poll_votes.actor_id')
            ->select('poll_votes.id', 'actors.uri')->orderBy('poll_votes.id')->limit(500);
        $plan = DB::select('EXPLAIN FORMAT=TRADITIONAL '.$backfill->toSql(), $backfill->getBindings());
        $votesPlan = collect($plan)->firstWhere('table', 'poll_votes');
        $this->assertNotNull($votesPlan);
        $this->assertNotNull($votesPlan->key);
        $this->assertNotSame('ALL', $votesPlan->type);
    }
}
