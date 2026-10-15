<?php

namespace Tests\Feature\Console;

use App\Application\Services\RemotePollVoter;
use App\Console\Commands\DeliverActivitiesCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Concerns\CreatesRemotePolls;
use Tests\TestCase;

class DeliverPollVotesCommandTest extends TestCase
{
    use CreatesRemotePolls, RefreshDatabase;

    // A real worker starts fresh; the full suite can exceed its 128 MB budget.
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_existing_delivery_command_drains_votes_with_sync_default(): void
    {
        [$voter, $poll] = $this->votingFixture();
        config(['queue.default' => 'sync']);
        app(RemotePollVoter::class)->vote($voter, $poll, [$poll->options[0]->id], $poll->optionsVersion());
        Http::fake(['https://remote.example/inbox' => Http::response('', 202)]);
        $this->artisan('openbook:deliver', ['--max-time' => 1])->assertSuccessful();
        $this->assertSame(0, DB::table('jobs')->count());
        Http::assertSentCount(1);
    }

    public function test_separate_default_database_queue_and_local_queue_are_both_drained(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.connection' => 'alternate']);
        $seen = [];
        Artisan::shouldReceive('call')->twice()->with('queue:work', \Mockery::on(function (array $args) use (&$seen): bool {
            $seen[] = $args['connection'];

            return $args['--max-time'] >= 1 && $args['--queue'] === 'delivery';
        }), \Mockery::any())->andReturn(0);
        $command = app(DeliverActivitiesCommand::class);
        $command->setLaravel(app());
        $tester = new CommandTester($command);
        $this->assertSame(0, $tester->execute(['--max-time' => 4]));
        $this->assertSame(['local_delivery', 'database'], array_values(array_unique($seen)));
    }

    public function test_standard_database_backend_is_drained_once(): void
    {
        config(['queue.default' => 'database']);
        Artisan::shouldReceive('call')->once()->with('queue:work', \Mockery::on(fn (array $args): bool => $args['connection'] === 'database' && $args['--queue'] === 'delivery'), \Mockery::any())->andReturn(0);
        $command = app(DeliverActivitiesCommand::class);
        $command->setLaravel(app());
        $tester = new CommandTester($command);
        $this->assertSame(0, $tester->execute(['--max-time' => 4]));
    }
}
