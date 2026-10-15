<?php

namespace Tests\Feature\Console;

use App\Application\Services\PollResultsProcessor;
use App\Application\Services\PostComposer;
use App\Domain\Polls\Poll;
use App\Domain\SocialGraph\Follow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class ProcessPollsCommandTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    private function publishedPoll(): Poll
    {
        Http::preventStrayRequests();
        $author = $this->createFullAccount('author')->actor;
        $poll = app(PostComposer::class)->compose($author, [
            'body' => 'Question', 'poll' => ['options' => ['Uno', 'Due'], 'multiple' => false, 'duration_seconds' => 300],
        ])->poll;
        $remote = $this->createRemoteActor('follower', 'remote.example');
        Follow::create(['follower_id' => $remote->id, 'following_id' => $author->id,
            'status' => 'accepted', 'requested_at' => now(), 'accepted_at' => now()]);
        Http::fake(['https://remote.example/inbox' => Http::response('', 202)]);

        return $poll;
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_existing_delivery_command_drains_local_questions_with_sync_default(): void
    {
        config(['queue.default' => 'sync']);
        $poll = $this->publishedPoll();
        app(PollResultsProcessor::class)->distribute($poll->id, 50);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->artisan('openbook:deliver', ['--max-time' => 1])->assertSuccessful();
        $this->assertSame(0, DB::table('jobs')->count());
        Http::assertSentCount(1);
        $this->assertSame('Question', Http::recorded()[0][0]->data()['object']['type']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_general_cron_closes_and_queues_before_running_normal_delivery(): void
    {
        $poll = $this->publishedPoll();
        $this->travelTo($poll->ends_at->copy()->addSecond());
        $this->artisan('openbook:cron', ['--max-time' => 5])->assertSuccessful();
        $this->assertTrue($poll->fresh()->closed);
        $this->assertFalse($poll->fresh()->needs_delivery);
        $this->assertSame(0, DB::table('jobs')->count());
        Http::assertSentCount(2);
        $this->assertSame($poll->ends_at->toAtomString(), Http::recorded()[0][0]->data()['object']['closed']);
    }
}
