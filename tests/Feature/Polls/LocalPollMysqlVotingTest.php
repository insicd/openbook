<?php

namespace Tests\Feature\Polls;

use App\Domain\Polls\PollVote;
use App\Domain\Polls\PollVoteChoice;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\TestsLocalPollVoting;
use Tests\Support\PollMysqlTestCase;

class LocalPollMysqlVotingTest extends PollMysqlTestCase
{
    use TestsLocalPollVoting;

    #[DataProvider('concurrentBallots')]
    public function test_concurrent_ballots_preserve_counts_and_final_selections(bool $sameActor): void
    {
        [$voter, $poll] = $this->votingFixture(true);
        $other = $sameActor ? $voter : $this->createFullAccount('other')->actor;
        $children = [];
        try {
            foreach ([$voter, $other] as $index => $actor) {
                $process = proc_open([PHP_BINARY, base_path('tests/Support/ConcurrentPollVote.php')],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $children[] = [$process, $pipes];
                stream_set_timeout($pipes[1], 20);
                fwrite($pipes[0], json_encode([
                    'database' => config('database.connections.poll_schema'), 'key' => config('app.key'),
                    'local' => true, 'voter' => $actor->id, 'poll' => $poll->id,
                    'choices' => [$poll->options[$index]->id], 'version' => $poll->optionsVersion(),
                ], JSON_THROW_ON_ERROR)."\n");
                $this->assertSame("ready\n", fgets($pipes[1]));
            }
            foreach ($children as [$process, $pipes]) {
                fwrite($pipes[0], "go\n");
                fflush($pipes[0]);
            }
            $ids = [];
            foreach ($children as [$process, $pipes]) {
                $id = trim(fgets($pipes[1]) ?: '');
                $this->assertNotSame('', $id, stream_get_contents($pipes[2]));
                $ids[] = $id;
            }
            $expected = $sameActor ? 1 : 2;
            $this->assertCount($expected, array_unique($ids));
            $this->assertSame($expected, $poll->fresh()->voters_count);
            $this->assertSame($expected, (int) $poll->fresh()->options->sum('votes_count'));
            $this->assertSame($expected + 1, $poll->fresh()->revision);
            $this->assertSame($expected, PollVote::count());
            $this->assertSame($expected, PollVoteChoice::count());
            $this->assertSame(0, DB::table('jobs')->count());
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

    public static function concurrentBallots(): array
    {
        return ['same voter' => [true], 'different voters' => [false]];
    }

    public function test_mysql_uses_unique_indexes_for_local_identity_and_note_deduplication(): void
    {
        [$voter, $poll] = $this->votingFixture();
        $vote = $this->castLocal($voter, $poll);
        $choice = $vote->choices->sole();
        $choice->remote_uri_hash = hash('sha256', 'https://remote.example/votes/one');
        $choice->save();
        $queries = [
            [PollVote::query()->where('poll_id', $poll->id)->where('voter_hash', PollVote::identityHash($voter->uri)), 'poll_votes_identity_unique'],
            [PollVoteChoice::query()->where('remote_uri_hash', $choice->remote_uri_hash), 'poll_vote_choices_remote_uri_hash_unique'],
            [$vote->choices()->where('option_id', $choice->option_id)->getQuery(), 'poll_choices_option_unique'],
        ];
        foreach ($queries as [$query, $index]) {
            $plan = DB::select('EXPLAIN FORMAT=TRADITIONAL '.$query->toSql(), $query->getBindings());
            $this->assertSame($index, $plan[0]->key, $query->toSql());
            $this->assertNotSame('ALL', $plan[0]->type);
        }
    }
}
