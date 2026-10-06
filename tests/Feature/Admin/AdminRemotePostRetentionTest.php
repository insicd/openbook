<?php

namespace Tests\Feature\Admin;

use App\Application\Services\InstanceSettings;
use App\Domain\Moderation\AuditLog;
use App\Domain\Posts\Post;
use App\Domain\Reactions\Announce;
use App\Federation\Actors\Actor;
use App\Infrastructure\Database\SystemSetting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class AdminRemotePostRetentionTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_admin_sees_defaults_and_persisted_periods_in_both_languages(): void
    {
        $admin = $this->createFullAccount('retentionadmin');
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->get(route('admin.database.index'))->assertOk()
            ->assertViewHas('nonPertinentDays', 0)->assertViewHas('pertinentDays', 0)
            ->assertSee(__('openbook.admin.database.retention_title'));

        SystemSetting::put(InstanceSettings::KEY_REMOTE_POST_NON_PERTINENT_RETENTION_DAYS, '30');
        SystemSetting::put(InstanceSettings::KEY_REMOTE_POST_PERTINENT_RETENTION_DAYS, '90');
        foreach (['it', 'en'] as $locale) {
            $admin->settings->forceFill(['locale' => $locale])->save();
            $this->get(route('admin.database.index'))->assertOk()
                ->assertViewHas('nonPertinentDays', 30)->assertViewHas('pertinentDays', 90)
                ->assertSee(__('openbook.admin.database.retention_intro', [], $locale));
        }
    }

    #[DataProvider('validPeriods')]
    public function test_admin_saves_only_retention_settings_without_running_cleanup(int $short, int $long): void
    {
        $admin = $this->createFullAccount('retentionsave');
        $admin->forceFill(['is_admin' => true])->save();
        SystemSetting::put(InstanceSettings::KEY_SITE_NAME, 'Unchanged');
        $cleanupQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$cleanupQueries): void {
            if (preg_match('/^(delete from|update|insert into) ["`](posts|comments|announces|jobs)["`]/', $query->sql)) {
                $cleanupQueries[] = $query->sql;
            }
        });
        $this->actingAs($admin)->put(route('admin.database.retention.update'), [
            InstanceSettings::KEY_REMOTE_POST_NON_PERTINENT_RETENTION_DAYS => $short,
            InstanceSettings::KEY_REMOTE_POST_PERTINENT_RETENTION_DAYS => $long,
            'site_name' => 'Ignored',
        ])->assertRedirect(route('admin.database.index'))->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('openbook.admin.database.retention_saved'));
        $settings = app(InstanceSettings::class);
        $this->assertSame([], $cleanupQueries);
        $this->assertSame($short, $settings->remotePostNonPertinentRetentionDays());
        $this->assertSame($long, $settings->remotePostPertinentRetentionDays());
        $this->assertSame('Unchanged', SystemSetting::get(InstanceSettings::KEY_SITE_NAME));
        $log = AuditLog::query()->where('action', 'settings.retention.update')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($short, $log->meta[InstanceSettings::KEY_REMOTE_POST_NON_PERTINENT_RETENTION_DAYS]);
        $this->assertSame($long, $log->meta[InstanceSettings::KEY_REMOTE_POST_PERTINENT_RETENTION_DAYS]);
    }

    public static function validPeriods(): array
    {
        return [[30, 90], [30, 30], [0, 90], [30, 0], [0, 0]];
    }

    #[DataProvider('invalidPeriods')]
    public function test_invalid_periods_leave_both_settings_unchanged(mixed $short, mixed $long, string $errorKey): void
    {
        $admin = $this->createFullAccount('retentioninvalid');
        $admin->forceFill(['is_admin' => true])->save();
        SystemSetting::put(InstanceSettings::KEY_REMOTE_POST_NON_PERTINENT_RETENTION_DAYS, '30');
        SystemSetting::put(InstanceSettings::KEY_REMOTE_POST_PERTINENT_RETENTION_DAYS, '90');
        $this->actingAs($admin)->from(route('admin.database.index', ['tab' => 'retention']))
            ->put(route('admin.database.retention.update'), [
                InstanceSettings::KEY_REMOTE_POST_NON_PERTINENT_RETENTION_DAYS => $short,
                InstanceSettings::KEY_REMOTE_POST_PERTINENT_RETENTION_DAYS => $long,
            ])->assertRedirect(route('admin.database.index', ['tab' => 'retention']))->assertSessionHasErrors($errorKey);
        $this->assertSame(30, app(InstanceSettings::class)->remotePostNonPertinentRetentionDays());
        $this->assertSame(90, app(InstanceSettings::class)->remotePostPertinentRetentionDays());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'settings.retention.update']);
    }

    public static function invalidPeriods(): array
    {
        $short = InstanceSettings::KEY_REMOTE_POST_NON_PERTINENT_RETENTION_DAYS;
        $long = InstanceSettings::KEY_REMOTE_POST_PERTINENT_RETENTION_DAYS;

        return [[30, 29, $long], [-1, 90, $short], [30, -1, $long],
            ['1.5', 90, $short], [30, 'abc', $long], [null, 90, $short], [30, null, $long],
            ['999999999999999999999', 90, $short]];
    }

    public function test_moderator_regular_user_and_guest_cannot_save_periods(): void
    {
        $data = [InstanceSettings::KEY_REMOTE_POST_NON_PERTINENT_RETENTION_DAYS => 30,
            InstanceSettings::KEY_REMOTE_POST_PERTINENT_RETENTION_DAYS => 90];
        $this->put(route('admin.database.retention.update'), $data)->assertRedirect(route('login'));
        $user = $this->createFullAccount('retentionregular');
        $this->actingAs($user)->put(route('admin.database.retention.update'), $data)->assertForbidden();
        $user->forceFill(['is_moderator' => true])->save();
        $this->actingAs($user)->put(route('admin.database.retention.update'), $data)->assertForbidden();
        $this->assertSame(0, app(InstanceSettings::class)->remotePostNonPertinentRetentionDays());
        $this->assertSame(0, app(InstanceSettings::class)->remotePostPertinentRetentionDays());
    }

    public function test_preview_shows_first_ten_posts_per_category_without_saving_or_deleting(): void
    {
        $this->travelTo(now()->startOfSecond());
        $admin = $this->createFullAccount('retentionpreview');
        $admin->forceFill(['is_admin' => true])->save();
        SystemSetting::put(InstanceSettings::KEY_REMOTE_POST_NON_PERTINENT_RETENTION_DAYS, '30');
        SystemSetting::put(InstanceSettings::KEY_REMOTE_POST_PERTINENT_RETENTION_DAYS, '90');
        $remote = Actor::query()->create([
            'type' => Actor::TYPE_PERSON, 'is_local' => false,
            'preferred_username' => 'preview', 'domain' => 'remote.test', 'uri' => 'https://remote.test/users/preview',
        ]);
        $ids = ['nonPertinent' => [], 'pertinent' => []];
        foreach ($ids as $category => $_) {
            foreach (range(1, 12) as $i) {
                $post = Post::query()->create([
                    'actor_id' => $remote->id, 'body' => 'Preview fixture body',
                    'uri' => 'https://remote.test/posts/'.Str::uuid(), 'published_at' => now()->subDays(100),
                ]);
                $post->forceFill(['created_at' => now()->subDays(100)])->saveQuietly();
                $ids[$category][] = $post->id;
                if ($category === 'pertinent') {
                    Announce::query()->create(['actor_id' => $admin->actor->id, 'post_id' => $post->id, 'is_direct' => true]);
                }
            }
        }
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^(delete from|update|insert into) ["`](posts|comments|system_settings|jobs)["`]/', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $response = $this->actingAs($admin)->get(route('admin.database.index', ['tab' => 'retention', 'preview' => 1]))
            ->assertOk()->assertSee('target="_blank" rel="noopener"', false)
            ->assertDontSee('Preview fixture body');
        foreach ($ids as $category => $postIds) {
            sort($postIds);
            $this->assertSame(array_slice($postIds, 0, 10), $response->viewData('preview')[$category]->pluck('id')->all());
            $response->assertSee(route('posts.show', ['post' => $postIds[0]]), false)
                ->assertDontSee(route('posts.show', ['post' => $postIds[10]]), false);
        }
        $this->assertSame([], $writes);
        $this->assertDatabaseCount('posts', 24);
        $this->assertSame(30, app(InstanceSettings::class)->remotePostNonPertinentRetentionDays());
        $this->assertSame(90, app(InstanceSettings::class)->remotePostPertinentRetentionDays());
    }

    public function test_preview_handles_disabled_and_empty_categories_and_is_only_loaded_on_request(): void
    {
        $admin = $this->createFullAccount('retentionpreviewempty');
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->get(route('admin.database.index'))->assertOk()->assertViewHas('preview', null);
        $this->get(route('admin.database.index', ['tab' => 'maintenance', 'preview' => 1]))
            ->assertOk()->assertViewHas('preview', null);
        SystemSetting::put(InstanceSettings::KEY_REMOTE_POST_PERTINENT_RETENTION_DAYS, '90');
        $this->get(route('admin.database.index', ['preview' => 1]))->assertOk()
            ->assertSee(__('openbook.admin.database.retention_preview_disabled'))
            ->assertSee(__('openbook.admin.database.retention_preview_empty'));
        $admin->forceFill(['is_admin' => false, 'is_moderator' => true])->save();
        $this->actingAs($admin)->get(route('admin.database.index', ['preview' => 1]))->assertForbidden();
    }
}
