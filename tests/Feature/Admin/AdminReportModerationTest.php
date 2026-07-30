<?php

namespace Tests\Feature\Admin;

use App\Application\Services\PostComposer;
use App\Application\Services\ReportManager;
use App\Domain\Moderation\Report;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class AdminReportModerationTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_staff_can_list_and_review_open_reports(): void
    {
        $author = $this->createFullAccount('autoreport');
        $reporter = $this->createFullAccount('reporteradmin');
        $mod = $this->createFullAccount('modreport');
        $mod->forceFill(['is_moderator' => true])->save();

        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Post da moderare.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $report = app(ReportManager::class)->reportPost($reporter, $post, [
            'reason' => Report::REASON_SPAM,
            'details' => 'Spam evidente.',
        ]);

        $this->actingAs($mod)
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee($reporter->username, false)
            ->assertSee(__('openbook.reports.reasons.spam'), false);

        $this->actingAs($mod)
            ->get(route('admin.reports.show', $report))
            ->assertOk()
            ->assertSee('Post da moderare.', false);

        $this->actingAs($mod)
            ->post(route('admin.reports.review', $report))
            ->assertRedirect();

        $this->assertSame(Report::STATUS_REVIEWED, $report->fresh()->status);
        $this->assertSame($mod->id, $report->fresh()->reviewed_by);
    }

    public function test_staff_can_dismiss_a_report(): void
    {
        $author = $this->createFullAccount('autordismiss');
        $reporter = $this->createFullAccount('reportdismiss');
        $mod = $this->createFullAccount('moddismiss');
        $mod->forceFill(['is_moderator' => true])->save();

        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Falso allarme.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $report = app(ReportManager::class)->reportPost($reporter, $post, [
            'reason' => Report::REASON_OTHER,
        ]);

        $this->actingAs($mod)
            ->post(route('admin.reports.dismiss', $report))
            ->assertRedirect();

        $this->assertSame(Report::STATUS_DISMISSED, $report->fresh()->status);
    }

    public function test_actioned_report_can_soft_delete_a_local_post(): void
    {
        $author = $this->createFullAccount('autoredelete');
        $reporter = $this->createFullAccount('reporterdelete');
        $mod = $this->createFullAccount('moddelete');
        $mod->forceFill(['is_moderator' => true])->save();

        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Da rimuovere.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $report = app(ReportManager::class)->reportPost($reporter, $post, [
            'reason' => Report::REASON_ILLEGAL,
        ]);

        $this->actingAs($mod)
            ->post(route('admin.reports.action', $report), [
                'delete_post' => '1',
            ])
            ->assertRedirect();

        $this->assertSame(Report::STATUS_ACTIONED, $report->fresh()->status);
        $this->assertSame(Post::STATUS_DELETED, $post->fresh()->status);
    }
}
