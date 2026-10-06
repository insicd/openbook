<?php

namespace Tests\Feature\Console;

use App\Application\Services\InstanceSettings;
use App\Domain\Posts\Post;
use App\Domain\Reactions\Announce;
use App\Federation\Actors\Actor;
use App\Infrastructure\Database\SystemSetting;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class PruneRemotePostsCommandTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function fixturePost(int $age, ?Actor $sharer = null): Post
    {
        $name = 'r'.Str::random(12);
        $author = Actor::query()->create([
            'type' => Actor::TYPE_PERSON, 'is_local' => false,
            'preferred_username' => $name, 'domain' => 'remote.test',
            'uri' => 'https://remote.test/users/'.$name,
        ]);
        $post = Post::query()->create([
            'actor_id' => $author->id, 'body' => 'PRIVATE-FIXTURE-BODY',
            'uri' => 'https://remote.test/posts/'.Str::uuid(),
            'published_at' => now()->subYears(5),
        ]);
        $post->forceFill(['created_at' => now()->subDays($age)])->saveQuietly();
        if ($sharer !== null) {
            Announce::query()->create(['actor_id' => $sharer->id, 'post_id' => $post->id, 'is_direct' => true]);
        }

        return $post;
    }

    private function enable(int $short = 30, int $long = 90): void
    {
        SystemSetting::put(InstanceSettings::KEY_REMOTE_POST_NON_PERTINENT_RETENTION_DAYS, (string) $short);
        SystemSetting::put(InstanceSettings::KEY_REMOTE_POST_PERTINENT_RETENTION_DAYS, (string) $long);
    }

    private function preview(array $options = []): string
    {
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $status = Artisan::call('openbook:prune-remote-posts', ['--dry-run' => true, ...$options]);
        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame([], $writes);
        $output = Artisan::output();
        $this->assertStringNotContainsString('PRIVATE-FIXTURE-BODY', $output);

        return $output;
    }

    public function test_defaults_report_both_disabled_without_writing(): void
    {
        $this->fixturePost(400);
        $output = $this->preview();
        $this->assertStringContainsString('Non pertinenti: disabilitata (0 giorni).', $output);
        $this->assertStringContainsString('Pertinenti: disabilitata (0 giorni).', $output);
        $this->assertStringNotContainsString('/posts/', $output);
    }

    public function test_one_batch_per_category_counts_selection_and_limits_link_sample(): void
    {
        $this->enable();
        $sharer = $this->createFullAccount('retentioncli')->actor;
        $world = $home = [];
        foreach ([140, 130, 120] as $age) {
            $world[] = $this->fixturePost($age);
            $home[] = $this->fixturePost($age, $sharer);
        }
        $output = $this->preview(['--batch-size' => 2, '--sample' => 1]);
        $this->assertStringContainsString('I conteggi non sono totali globali.', $output);
        $this->assertStringContainsString('Non pertinenti: 30 giorni; candidati nel batch: 2.', $output);
        $this->assertStringContainsString('Pertinenti: 90 giorni; candidati nel batch: 2.', $output);
        $this->assertStringContainsString(route('posts.show', $world[0]), $output);
        $this->assertStringContainsString(route('posts.show', $home[0]), $output);
        foreach ([$world[1], $world[2], $home[1], $home[2]] as $hidden) {
            $this->assertStringNotContainsString($hidden->id, $output);
        }
        $this->assertSame(6, Post::query()->count());
        $this->assertSame(3, Announce::query()->count());
    }

    public function test_one_disabled_category_does_not_disable_the_other(): void
    {
        $this->enable(30, 0);
        $post = $this->fixturePost(100);
        $output = $this->preview();
        $this->assertStringContainsString('Non pertinenti: 30 giorni; candidati nel batch: 1.', $output);
        $this->assertStringContainsString('Pertinenti: disabilitata', $output);
        $this->assertStringContainsString(route('posts.show', $post), $output);
        $this->enable(0, 90);
        $sharer = $this->createFullAccount('retentiononlylong')->actor;
        $home = $this->fixturePost(100, $sharer);
        $output = $this->preview();
        $this->assertStringContainsString('Non pertinenti: disabilitata', $output);
        $this->assertStringContainsString('Pertinenti: 90 giorni; candidati nel batch: 1.', $output);
        $this->assertStringContainsString(route('posts.show', $home), $output);
    }

    public function test_empty_selection_and_zero_sample_are_supported(): void
    {
        $this->enable();
        $post = $this->fixturePost(100);
        $output = $this->preview(['--sample' => 0]);
        $this->assertStringContainsString('Non pertinenti: 30 giorni; candidati nel batch: 1.', $output);
        $this->assertStringContainsString('Pertinenti: 90 giorni; candidati nel batch: 0.', $output);
        $this->assertStringNotContainsString($post->id, $output);
    }

    public function test_sample_larger_than_batch_only_shows_selected_posts_in_order(): void
    {
        $this->enable();
        $oldest = $this->fixturePost(140);
        $next = $this->fixturePost(130);
        $outside = $this->fixturePost(120);
        $output = $this->preview(['--batch-size' => 2, '--sample' => 20]);
        $this->assertStringContainsString(route('posts.show', $oldest), $output);
        $this->assertStringContainsString(route('posts.show', $next), $output);
        $this->assertLessThan(strpos($output, $next->id), strpos($output, $oldest->id));
        $this->assertStringNotContainsString($outside->id, $output);
    }

    public function test_running_without_dry_run_is_rejected(): void
    {
        $this->artisan('openbook:prune-remote-posts')
            ->expectsOutputToContain('aggiungere --dry-run')
            ->assertExitCode(Command::INVALID);
    }

    #[DataProvider('invalidOptions')]
    public function test_invalid_numeric_options_are_rejected(string $option, string $value): void
    {
        $this->artisan('openbook:prune-remote-posts', ['--dry-run' => true, $option => $value])
            ->expectsOutputToContain('--batch-size deve essere un intero positivo')
            ->assertExitCode(Command::INVALID);
    }

    public static function invalidOptions(): array
    {
        return [
            ['--batch-size', '0'], ['--batch-size', '-1'], ['--batch-size', '1.5'],
            ['--batch-size', 'foo'], ['--batch-size', '9999999999999999999999999'],
            ['--sample', '-1'], ['--sample', '1.5'], ['--sample', 'foo'],
        ];
    }
}
