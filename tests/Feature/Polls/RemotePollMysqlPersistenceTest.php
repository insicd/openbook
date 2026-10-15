<?php

namespace Tests\Feature\Polls;

use App\Domain\Polls\RemotePoll;
use App\Federation\Inbox\RemoteNoteUpserter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\TestsRemotePollPersistence;
use Tests\TestCase;

/** Opt-in: creates and removes its own random schema, never migrates the configured local DB. */
class RemotePollMysqlPersistenceTest extends TestCase
{
    use TestsRemotePollPersistence;

    private ?string $temporarySchema = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('OPENBOOK_TEST_POLLS_MYSQL', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Set OPENBOOK_TEST_POLLS_MYSQL=1 to run isolated MySQL poll tests.');
        }

        $connection = array_replace(config('database.connections.mysql'), [
            'url' => null,
            'host' => env('OPENBOOK_TEST_MYSQL_HOST', '127.0.0.1'),
            'port' => env('OPENBOOK_TEST_MYSQL_PORT', '3307'),
            'unix_socket' => env('OPENBOOK_TEST_MYSQL_SOCKET', ''),
            'database' => null,
            'username' => env('OPENBOOK_TEST_MYSQL_USERNAME', 'root'),
            'password' => env('OPENBOOK_TEST_MYSQL_PASSWORD', ''),
            'prefix' => '',
        ]);
        config(['database.connections.poll_admin' => $connection]);
        $schema = 'openbook_polls_test_'.Str::lower(Str::random(16));
        DB::connection('poll_admin')->statement('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->temporarySchema = $schema;
        config(['database.connections.poll_schema' => array_replace($connection, ['database' => $schema]), 'database.default' => 'poll_schema']);
        try {
            Artisan::call('migrate', ['--database' => 'poll_schema', '--force' => true]);
        } catch (\Throwable $exception) {
            $this->dropTemporarySchema();
            config(['database.default' => 'sqlite']);
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->dropTemporarySchema();
        } finally {
            DB::purge('poll_admin');
            config(['database.default' => 'sqlite']);
            parent::tearDown();
        }
    }

    private function dropTemporarySchema(): void
    {
        if ($this->temporarySchema !== null) {
            DB::purge('poll_schema');
            DB::connection('poll_admin')->statement('DROP DATABASE `'.$this->temporarySchema.'`');
            $this->temporarySchema = null;
        }
    }

    public function test_new_migration_rolls_back_and_reapplies_on_mysql(): void
    {
        $migration = require base_path('database/migrations/2026_10_15_000000_create_remote_polls_tables.php');
        $migration->down();
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('remote_polls'));
        $migration->up();
        $this->newPoll();
        $this->assertSame(1, DB::table('remote_polls')->count());
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
            [RemotePoll::where('post_id', $target->post_id), 'remote_polls_post_id_unique'],
            [$target->options()->getQuery(), 'remote_poll_options_position_unique'],
            [$target->votes()->where('actor_id', $actor->id)->getQuery(), 'remote_poll_votes_actor_unique'],
            [$target->votes()->sole()->choices()->getQuery(), 'remote_poll_vote_choices_position_unique'],
        ];
        foreach ($queries as [$query, $index]) {
            $plan = DB::select('EXPLAIN FORMAT=TRADITIONAL '.$query->toSql(), $query->getBindings());
            $this->assertSame($index, $plan[0]->key, $query->toSql());
            $this->assertStringNotContainsString('filesort', strtolower($plan[0]->Extra ?? ''));
        }

        $observedQueries = [];
        DB::listen(function ($event) use (&$observedQueries): void {
            if (str_contains($event->sql, '`remote_poll_options`')
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
            $this->assertContains($plan[0]->key, ['remote_poll_options_position_unique', 'remote_poll_options_name_unique'], $sql);
            $this->assertStringNotContainsString('filesort', strtolower($plan[0]->Extra ?? ''));
        }

    }
}
