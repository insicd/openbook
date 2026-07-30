<?php

namespace Tests\Feature\Admin;

use App\Application\Services\CommentComposer;
use App\Application\Services\PostComposer;
use App\Domain\Comments\Comment;
use App\Domain\Moderation\Report;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class AdminCommentReportTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_a_user_can_report_a_comment_and_staff_can_action_it(): void
    {
        $author = $this->createFullAccount('autorecommento');
        $reporter = $this->createFullAccount('reportercommento');
        $mod = $this->createFullAccount('modcommento');
        $mod->forceFill(['is_moderator' => true])->save();

        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Post padre.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $comment = app(CommentComposer::class)->compose($author->actor, $post, 'Commento discutibile.');

        $this->actingAs($reporter)
            ->post(route('comments.report.store', $comment), [
                'reason' => Report::REASON_SPAM,
            ])
            ->assertRedirect(route('posts.show', $post));

        $report = Report::query()->where('comment_id', $comment->id)->firstOrFail();

        $this->actingAs($mod)
            ->post(route('admin.reports.action', $report), [
                'delete_comment' => '1',
            ])
            ->assertRedirect();

        $this->assertSame(Report::STATUS_ACTIONED, $report->fresh()->status);
        $this->assertSame(Comment::STATUS_DELETED, $comment->fresh()->status);
    }
}
