<?php

namespace Tests\Feature\Polls;

use App\Application\Queries\PollWorkQuery;
use App\Application\Services\CommunityRegistrar;
use App\Application\Services\PostComposer;
use App\Domain\Polls\PollVote;
use App\Federation\Polls\PollResultRecipients;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\TestsPollResults;
use Tests\Support\PollMysqlTestCase;

class PollResultsMysqlTest extends PollMysqlTestCase
{
    use TestsPollResults;

    #[DataProvider('distributionBatches')]
    public function test_concurrent_cron_closes_once_and_never_repeats_a_committed_batch(int $batch): void
    {
        $poll = $this->pollFixture();
        foreach (['a', 'b'] as $host) {
            $this->follow($this->createRemoteActor($host, $host.'.example'), $poll->post->actor_id);
        }
        $this->travelTo($poll->ends_at);
        $children = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $process = proc_open([PHP_BINARY, base_path('tests/Support/ConcurrentPollProcessing.php')],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $children[] = [$process, $pipes];
                stream_set_timeout($pipes[1], 20);
                fwrite($pipes[0], json_encode([
                    'database' => config('database.connections.poll_schema'), 'key' => config('app.key'),
                    'poll' => $poll->id, 'batch' => $batch, 'close' => true, 'clock' => now()->toIso8601String(),
                ], JSON_THROW_ON_ERROR)."\n");
                $this->assertSame("ready\n", fgets($pipes[1]));
            }
            foreach ($children as [$process, $pipes]) {
                fwrite($pipes[0], "go\n");
                fflush($pipes[0]);
            }
            foreach ($children as [$process, $pipes]) {
                $result = trim(fgets($pipes[1]) ?: '');
                $this->assertNotSame('', $result, stream_get_contents($pipes[2]));
            }
            $this->assertSame(2, $poll->fresh()->revision);
            $this->assertTrue($poll->fresh()->closed);
            $this->assertCount(4, $this->jobs());
            foreach (['Create', 'Update'] as $type) {
                $jobs = array_values(array_filter($this->jobs(), fn ($job) => $job->activity['type'] === $type));
                $this->assertCount(2, $jobs);
                $this->assertSame($jobs[0]->activity, $jobs[1]->activity);
                $this->assertNotSame($jobs[0]->inboxUrl, $jobs[1]->inboxUrl);
            }
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

    public static function distributionBatches(): array
    {
        return ['complete snapshot' => [50], 'partial snapshot' => [1]];
    }

    public function test_mysql_uses_source_indexes_in_every_recipient_union_branch(): void
    {
        $poll = $this->pollFixture();
        $owner = $poll->post->actor->user;
        $community = app(CommunityRegistrar::class)->register($owner, ['slug' => 'plans', 'name' => 'Plans']);
        $poll->post->update(['community_id' => $community->id]);
        $other = app(PostComposer::class)->compose($this->createFullAccount('other')->actor, [
            'body' => 'Other Question', 'poll' => ['options' => ['Uno', 'Due'], 'multiple' => false, 'duration_seconds' => 300],
        ])->poll;
        $actors = $endpoints = $follows = $votes = $mentions = [];
        for ($i = 0; $i < 1000; $i++) {
            $id = (string) Str::uuid();
            $uri = 'https://recipient'.$i.'.example/users/voter';
            $actors[] = ['id' => $id, 'type' => 'person', 'is_local' => false, 'preferred_username' => 'voter',
                'domain' => 'recipient'.$i.'.example', 'uri' => $uri, 'status' => 'active'];
            $endpoints[] = ['id' => (string) Str::uuid(), 'actor_id' => $id, 'inbox' => 'https://recipient'.$i.'.example/inbox'];
            $target = $i < 10 ? $poll : $other;
            $follows[] = ['id' => (string) Str::uuid(), 'follower_id' => $id, 'following_id' => $target->post->actor_id,
                'status' => 'accepted', 'requested_at' => now()];
            $votes[] = ['id' => (string) Str::uuid(), 'poll_id' => $target->id, 'actor_id' => $id,
                'voter_hash' => PollVote::identityHash($uri), 'created_at' => now()];
            $mentions[] = ['id' => (string) Str::uuid(), 'mentionable_type' => 'post', 'mentionable_id' => $target->post_id, 'actor_id' => $id];
            if ($i < 10) {
                $follows[] = ['id' => (string) Str::uuid(), 'follower_id' => $id, 'following_id' => $community->actor_id,
                    'status' => 'accepted', 'requested_at' => now()];
            }
        }
        foreach (['actors' => $actors, 'actor_endpoints' => $endpoints, 'follows' => $follows, 'poll_votes' => $votes, 'mentions' => $mentions] as $table => $rows) {
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
        $post = $poll->post->fresh();
        $query = app(PollResultRecipients::class)->query($post, $poll)->where('destination', '>', 'https://recipient0.example/inbox')
            ->orderBy('destination')->limit(3);
        $plan = DB::select('EXPLAIN FORMAT=TRADITIONAL '.$query->toSql(), $query->getBindings());
        $sources = collect($plan)->filter(fn ($row) => in_array($row->table, ['follows', 'poll_votes', 'mentions'], true)
            && in_array($row->select_type, ['DERIVED', 'UNION'], true));
        $this->assertCount(4, $sources, json_encode($plan));
        foreach ($sources as $row) {
            $this->assertNotNull($row->key, json_encode($plan));
            $this->assertNotSame('ALL', $row->type, json_encode($plan));
        }
        $this->assertCount(3, $query->get());
        $posts = $polls = [];
        for ($i = 0; $i < 1000; $i++) {
            $postId = (string) Str::uuid();
            $posts[] = ['id' => $postId, 'actor_id' => $owner->actor->id, 'body' => 'Plan fixture',
                'visibility' => 'public', 'status' => 'published', 'published_at' => now()];
            $polls[] = ['id' => (string) Str::uuid(), 'post_id' => $postId,
                'multiple' => false, 'needs_delivery' => $i < 5, 'ends_at' => $i < 5 ? now()->subMinute() : now()->addDay()];
        }
        foreach (['posts' => $posts, 'polls' => $polls] as $table => $rows) {
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
        foreach ([app(PollWorkQuery::class)->pending()->limit(100), app(PollWorkQuery::class)->expired()->limit(100),
        ] as $selection) {
            $plan = DB::select('EXPLAIN FORMAT=TRADITIONAL '.$selection->toSql(), $selection->getBindings());
            $pollPlan = collect($plan)->firstWhere('table', 'polls');
            $this->assertSame(str_contains($selection->toSql(), 'needs_delivery') ? 'polls_delivery_index' : 'polls_expiry_index', $pollPlan->key, json_encode($plan));
        }
        // Cached remote closures must not dominate the local cron selection.
        $remotePosts = $remotePolls = [];
        for ($i = 0; $i < 10000; $i++) {
            $postId = (string) Str::uuid();
            $remotePosts[] = ['id' => $postId, 'actor_id' => $actors[0]['id'],
                'uri' => 'https://remote.example/statuses/'.$i, 'body' => 'Remote Question',
                'visibility' => 'public', 'status' => 'published', 'published_at' => now()->subDay()];
            $remotePolls[] = ['id' => (string) Str::uuid(), 'post_id' => $postId, 'multiple' => false,
                'closed' => true, 'ends_at' => now()->subMinute()];
        }
        foreach (['posts' => $remotePosts, 'polls' => $remotePolls] as $table => $rows) {
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
        DB::select('ANALYZE TABLE posts, polls');
        $selection = app(PollWorkQuery::class)->expired()->limit(100);
        $plan = DB::select('EXPLAIN FORMAT=TRADITIONAL '.$selection->toSql(), $selection->getBindings());
        $this->assertNotNull($plan[0]->key, json_encode($plan));
        $this->assertLessThan(5000, (int) $plan[0]->rows, json_encode($plan));
        $this->assertCount(5, $selection->get());
    }
}
