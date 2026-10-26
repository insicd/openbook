<?php

namespace Tests\Feature\Admin;

use App\Application\Queries\ReportModerationQuery;
use App\Application\Services\CommunityRegistrar;
use App\Application\Services\ReportManager;
use App\Domain\Accounts\User;
use App\Domain\Moderation\Report;
use App\Domain\Posts\Mention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesReportFixtures;
use Tests\TestCase;

class AdminFederatedReportTest extends TestCase
{
    use CreatesReportFixtures, RefreshDatabase;

    private function moderator(bool $admin = false): User
    {
        $user = $this->createFullAccount('moderator');
        $user->forceFill(['is_admin' => $admin, 'is_moderator' => ! $admin])->save();

        return $user;
    }

    #[DataProvider('staffRoles')]
    public function test_staff_can_examine_all_types_review_and_then_archive_the_same_case(bool $admin): void
    {
        Queue::fake();
        [$sender, $targets] = $this->reportFixture();
        $report = $this->storeReport($sender, $targets);
        $mod = $this->moderator($admin);
        $this->actingAs($mod)->get(route('admin.reports.index'))->assertOk()
            ->assertSee(__('openbook.admin.reports.origin_federated'))
            ->assertSee($sender->uri)->assertSee('remote.example');
        $response = $this->get(route('admin.reports.show', $report))->assertOk()
            ->assertSee('Reason')->assertSee(__('openbook.admin.reports.original_reporter_unknown'));
        foreach (['actor', 'post', 'comment', 'event', 'event_comment'] as $type) {
            $response->assertSee('data-report-target="'.$type.'"', false);
        }
        $response->assertSee('Event comment')->assertSee('Event');
        $this->post(route('admin.reports.review', $report))->assertRedirect();
        $this->assertSame(Report::STATUS_REVIEWED, $report->fresh()->status);
        $this->assertNull($report->fresh()->closed_at);
        $this->get(route('admin.reports.show', $report))->assertSee(route('admin.reports.dismiss', $report), false);
        $this->post(route('admin.reports.dismiss', $report))->assertRedirect();
        $this->assertSame(Report::STATUS_DISMISSED, $report->fresh()->status);
        $this->assertNotNull($report->fresh()->closed_at);
        $this->assertSame($mod->id, $report->fresh()->reviewed_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.reviewed', 'subject_id' => $report->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.dismissed', 'subject_id' => $report->id]);
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNothingPushed();
    }

    public static function staffRoles(): array
    {
        return ['moderator' => [false], 'administrator' => [true]];
    }

    public function test_guests_and_regular_users_cannot_read_or_change_a_federated_case(): void
    {
        [$sender, $targets] = $this->reportFixture();
        $report = $this->storeReport($sender, $targets);
        foreach ([null, $this->createFullAccount('ordinary')] as $user) {
            if ($user !== null) {
                $this->actingAs($user);
            }
            foreach (['admin.reports.index', 'admin.reports.show'] as $route) {
                $response = $this->get(route($route, $route === 'admin.reports.show' ? $report : []));
                $user === null ? $response->assertRedirect(route('login')) : $response->assertForbidden();
                $response->assertDontSee('Reason')->assertDontSee($sender->uri);
            }
            foreach (['review', 'dismiss', 'action'] as $action) {
                $response = $this->post(route('admin.reports.'.$action, $report));
                $user === null ? $response->assertRedirect(route('login')) : $response->assertForbidden();
            }
        }
        $this->assertSame(Report::STATUS_OPEN, $report->fresh()->status);
    }

    public function test_private_previews_follow_existing_post_and_event_access_rules(): void
    {
        [$sender, $targets] = $this->reportFixture();
        $targets[1]['subject']->update(['visibility' => 'direct', 'title' => 'Hidden title', 'body' => 'Hidden post']);
        $targets[2]['subject']->update(['body' => 'Hidden comment']);
        $targets[3]['subject']->update(['visibility' => 'direct', 'name' => 'Hidden event', 'content' => 'Hidden event body']);
        $targets[4]['subject']->update(['body' => 'Hidden event comment']);
        $report = $this->storeReport($sender, $targets);
        $mod = $this->moderator(true);
        $response = $this->actingAs($mod)->get(route('admin.reports.show', $report))->assertOk()
            ->assertSee(__('openbook.admin.reports.target_restricted'));
        foreach (['Hidden title', 'Hidden post', 'Hidden comment', 'Hidden event', 'Hidden event body', 'Hidden event comment'] as $body) {
            $response->assertDontSee($body);
        }
        $previews = app(ReportModerationQuery::class)->previews($report, $mod->actor);
        $this->assertCount(4, array_filter($previews, fn ($preview) => $preview['state'] === 'restricted'));

        Mention::query()->create(['mentionable_type' => 'post', 'mentionable_id' => $targets[1]['subject']->id, 'actor_id' => $mod->actor->id]);
        $targets[3]['subject']->recipients()->attach($mod->actor->id);
        $this->get(route('admin.reports.show', $report))->assertOk()
            ->assertSee('Hidden post')->assertSee('Hidden comment')->assertSee('Hidden event body')->assertSee('Hidden event comment');
    }

    public function test_local_reports_also_respect_private_community_access(): void
    {
        [$sender, $targets] = $this->reportFixture();
        $author = $targets[0]['subject']->user;
        $community = app(CommunityRegistrar::class)->register($author, ['slug' => 'privategroup', 'name' => 'Private group', 'is_private' => true]);
        $post = $targets[1]['subject'];
        $post->update(['community_id' => $community->id, 'body' => 'Private community evidence']);
        $localReporter = $this->createFullAccount('localreporter');
        $report = app(ReportManager::class)->reportPost($localReporter, $post, ['reason' => 'other']);
        $mod = $this->moderator();
        $this->actingAs($mod)->get(route('admin.reports.show', $report))->assertOk()
            ->assertSee(__('openbook.admin.reports.target_restricted'))->assertDontSee('Private community evidence');
    }

    public function test_automatic_closure_and_missing_targets_are_presented_without_a_fictitious_reviewer(): void
    {
        [$sender, $targets] = $this->reportFixture();
        $targets[1]['subject']->update(['status' => 'deleted', 'body' => 'Deleted evidence must stay hidden']);
        $report = $this->storeReport($sender, [$targets[0], $targets[1], [
            'type' => 'event', 'uri' => 'https://openbook.test/eventi/'.Str::uuid(), 'subject' => null,
        ]]);
        $response = $this->actingAs($this->moderator())->get(route('admin.reports.show', $report))->assertOk()
            ->assertSee(__('openbook.admin.reports.already_handled'))
            ->assertSee(__('openbook.admin.reports.automatically_handled'))
            ->assertSee(__('openbook.admin.reports.target_missing'))
            ->assertDontSee('Deleted evidence must stay hidden');
        $response->assertDontSee(route('admin.reports.review', $report), false);
        $this->assertNull($report->reviewed_by);
    }

    public function test_hostile_text_and_deleted_sender_remain_safe_and_identifiable(): void
    {
        [$sender, $targets] = $this->reportFixture();
        $report = $this->storeReport($sender, $targets, details: '<script>alert("reason")</script>');
        $report->targets()->where('type', 'post')->update(['uri' => 'javascript:alert("uri")']);
        $targets[1]['subject']->update(['title' => '<script>alert("title")</script>', 'body' => '<img src=x onerror=alert(1)>']);
        $senderUri = $sender->uri;
        $sender->delete();
        $response = $this->actingAs($this->moderator())->get(route('admin.reports.show', $report))->assertOk()
            ->assertSee($senderUri)->assertSee('remote.example')
            ->assertSee('&lt;script&gt;alert', false)
            ->assertDontSee('<script>alert', false)
            ->assertDontSee('<img src=x', false)
            ->assertDontSee('href="javascript:', false);
        $this->assertNull($report->fresh()->reporter_actor_id);
    }

    public function test_local_and_federated_reports_share_stable_pagination_status_filters_and_dashboard_counts(): void
    {
        [$sender, $targets] = $this->reportFixture();
        $fed = $this->storeReport($sender, $targets);
        $reporter = $this->createFullAccount('localreporter');
        $local = app(ReportManager::class)->reportPost($reporter, $targets[1]['subject'], ['reason' => 'spam']);
        $mod = $this->moderator();
        $rows = [];
        for ($i = 0; $i < 23; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'origin' => 'federated', 'reason' => 'other',
                'status' => 'open', 'created_at' => $fed->created_at, 'updated_at' => now()];
        }
        DB::table('reports')->insert($rows);
        $first = $this->actingAs($mod)->get(route('admin.reports.index'))->assertOk()->viewData('reports');
        $second = $this->get(route('admin.reports.index', ['page' => 2]))->assertOk()->viewData('reports');
        $this->assertSame(25, $first->total());
        $this->assertSame(20, $first->count());
        $this->assertSame(5, $second->count());
        $this->assertCount(25, array_unique([...$first->modelKeys(), ...$second->modelKeys()]));
        $this->get(route('admin.dashboard'))->assertViewHas('openReportsCount', 25);
        app(ReportManager::class)->dismiss($fed, $mod);
        $this->get(route('admin.reports.index', ['status' => 'dismissed']))->assertViewHas('reports', fn ($reports) => $reports->total() === 1 && $reports->first()->id === $fed->id);
        $this->get(route('admin.reports.index', ['status' => 'all']))->assertViewHas('reports', fn ($reports) => $reports->total() === 25);
        $this->get(route('admin.reports.index', ['status' => 'invalid']))->assertViewHas('status', Report::STATUS_OPEN);
        $this->assertSame(Report::ORIGIN_LOCAL, $local->fresh()->origin);
    }
}
