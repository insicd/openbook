<?php

namespace Tests\Feature\Rest;

use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\UsesPassportKeys;
use Tests\TestCase;

class PostCommentMysqlTest extends TestCase
{
    use CreatesAccounts, UsesPassportKeys;

    public function test_actual_comment_queries_use_indexes_on_mysql(): void
    {
        if (! filter_var(env('OPENBOOK_TEST_COMMENTS_MYSQL', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Set OPENBOOK_TEST_COMMENTS_MYSQL=1 to verify isolated comment query plans.');
        }
        $connection = array_replace(config('database.connections.mysql'), [
            'url' => null, 'database' => null, 'prefix' => '',
            'host' => env('OPENBOOK_TEST_MYSQL_HOST', '127.0.0.1'),
            'port' => env('OPENBOOK_TEST_MYSQL_PORT', '3307'),
            'unix_socket' => env('OPENBOOK_TEST_MYSQL_SOCKET', ''),
            'username' => env('OPENBOOK_TEST_MYSQL_USERNAME', 'root'),
            'password' => env('OPENBOOK_TEST_MYSQL_PASSWORD', ''),
        ]);
        config(['database.connections.comments_admin' => $connection]);
        $schema = 'openbook_comments_test_'.Str::lower(Str::random(16));
        DB::connection('comments_admin')->statement('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            config(['database.connections.comments_test' => array_replace($connection, ['database' => $schema]),
                'database.default' => 'comments_test']);
            Artisan::call('migrate', ['--database' => 'comments_test', '--force' => true]);
            Queue::fake();
            Http::preventStrayRequests();
            $this->configurePassportKeys();
            $owner = $this->createFullAccount('mysqlcomments');
            Passport::actingAs($owner, ['api'], 'rest');
            $this->withToken('test-token');
            $posts = [];
            for ($i = 0; $i < 10; $i++) {
                $posts[] = Post::create(['actor_id' => $owner->actor->id, 'body' => 'Post', 'visibility' => 'public', 'published_at' => now()]);
            }
            $rows = [];
            for ($i = 0; $i < 12000; $i++) {
                $rows[] = ['id' => (string) Str::uuid(), 'post_id' => $posts[$i % 10]->id,
                    'actor_id' => $owner->actor->id, 'body' => 'Comment',
                    'status' => $i % 7 === 0 ? 'deleted' : 'published',
                    'created_at' => now()->subSeconds(intdiv($i, 10)), 'updated_at' => now()];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('comments')->insert($chunk);
            }
            DB::statement('ANALYZE TABLE comments');
            $plans = [];
            DB::listen(function (QueryExecuted $event) use (&$plans): void {
                if (! str_starts_with(strtolower($event->sql), 'select') || ! str_contains($event->sql, 'from `comments`')) {
                    return;
                }
                foreach (DB::select('EXPLAIN FORMAT=TRADITIONAL '.$event->sql, $event->bindings) as $row) {
                    if ($row->table === 'comments') {
                        $plans[] = $row;
                        $this->assertNotNull($row->key, $event->sql.' must use an index');
                        $this->assertNotSame('ALL', $row->type, $event->sql.' must not scan the full table');
                        if (str_contains($event->sql, 'order by `created_at`')) {
                            $this->assertStringNotContainsString('filesort', strtolower($row->Extra));
                        }
                    }
                }
            });
            $post = $posts[0];
            $base = '/rest/v1/posts/'.$post->id.'/comments';
            foreach (['asc', 'desc'] as $direction) {
                $first = $this->getJson($base.'?direction='.$direction)->assertOk()->assertJsonCount(20);
                $last = $first->json('19.commentId');
                $this->getJson($base.'?direction='.$direction.'&after='.$last)->assertOk()->assertJsonCount(20);
            }
            $parent = Comment::where('post_id', $post->id)->where('status', 'published')->firstOrFail();
            $this->postJson($base, ['commentContent' => 'New reply', 'commentParentId' => $parent->id])->assertCreated();
            $this->patchJson($base.'/'.$parent->id, ['commentContent' => 'Edited'])->assertOk();
            $this->deleteJson($base.'/'.$parent->id)->assertNoContent();
            $this->assertGreaterThanOrEqual(10, count($plans));
            $keys = array_values(array_unique(array_map(fn ($row) => $row->key, $plans)));
            fwrite(STDERR, "\nComment query indexes: ".implode(', ', $keys)."\n");
        } finally {
            DB::purge('comments_test');
            config(['database.default' => 'sqlite']);
            DB::connection('comments_admin')->statement('DROP DATABASE `'.$schema.'`');
            DB::purge('comments_admin');
        }
    }
}
