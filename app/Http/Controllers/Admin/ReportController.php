<?php

namespace App\Http\Controllers\Admin;

use App\Application\Services\CommentSoftDeleter;
use App\Application\Services\ReportManager;
use App\Domain\Comments\Comment;
use App\Domain\Moderation\Report;
use App\Domain\Posts\Post;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\ActivitySerializer;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportManager $reportManager,
        private readonly ActivityDelivery $delivery,
        private readonly CommentSoftDeleter $commentSoftDeleter,
    ) {}

    public function index(Request $request): View
    {
        $status = (string) $request->query('status', Report::STATUS_OPEN);
        $allowed = [
            Report::STATUS_OPEN,
            Report::STATUS_REVIEWED,
            Report::STATUS_DISMISSED,
            Report::STATUS_ACTIONED,
            'all',
        ];

        if (! in_array($status, $allowed, true)) {
            $status = Report::STATUS_OPEN;
        }

        $query = Report::query()
            ->with([
                'reporter.profile',
                'post.actor.user.profile',
                'comment.actor.user.profile',
                'comment.post',
                'reviewer',
            ])
            ->orderByDesc('created_at');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        return view('admin.reports.index', [
            'reports' => $query->paginate(20)->withQueryString(),
            'status' => $status,
        ]);
    }

    public function show(Report $report): View
    {
        $report->load([
            'reporter.profile',
            'reviewer',
            'post.actor.user.profile',
            'post.media.thumbnail',
            'post.hashtags',
            'post.quotedPost.actor.user.profile',
            'post.quotedPost.media.thumbnail',
            'post.quotedPost.hashtags',
            'comment.actor.user.profile',
            'comment.post.actor.user.profile',
        ]);

        if ($report->post) {
            Post::annotateViewerState([$report->post], auth()->user()->actor);
        }

        return view('admin.reports.show', [
            'report' => $report,
        ]);
    }

    public function review(Report $report): RedirectResponse
    {
        $this->reportManager->markReviewed($report, auth()->user());

        return back()->with('status', __('openbook.admin.reports.marked_reviewed'));
    }

    public function dismiss(Report $report): RedirectResponse
    {
        $this->reportManager->dismiss($report, auth()->user());

        return back()->with('status', __('openbook.admin.reports.marked_dismissed'));
    }

    public function action(Request $request, Report $report): RedirectResponse
    {
        $report->loadMissing([
            'post.actor',
            'post.mentions.actor',
            'comment.actor',
            'comment.mentions.actor',
            'comment.post.actor',
            'comment.parent.actor',
        ]);

        if ($request->boolean('delete_post') && $report->post) {
            $this->softDeleteLocalPost($report->post);
        }

        if ($request->boolean('delete_comment') && $report->comment) {
            $this->softDeleteLocalComment($report->comment);
        }

        $this->reportManager->markActioned($report, auth()->user());

        return back()->with('status', __('openbook.admin.reports.marked_actioned'));
    }

    private function softDeleteLocalPost(Post $post): void
    {
        if ($post->isRemote() || ! $post->isPublished() || ! auth()->user()->canModerate()) {
            return;
        }

        $isLocalAuthor = $post->actor->isLocal();
        $post->update([
            'title' => null,
            'content_warning' => null,
            'body' => '',
            'status' => Post::STATUS_DELETED,
        ]);

        if ($isLocalAuthor) {
            $post->load('mentions.actor', 'actor');
            $this->delivery->deliverContent($post, ActivitySerializer::delete($post));
        }
    }

    private function softDeleteLocalComment(Comment $comment): void
    {
        if ($comment->isRemote() || $comment->status !== Comment::STATUS_PUBLISHED || ! auth()->user()->canModerate()) {
            return;
        }

        $comment->loadMissing('mentions.actor', 'actor', 'post', 'parent.actor');
        $isLocalAuthor = $comment->actor->isLocal();

        $this->commentSoftDeleter->delete($comment);

        if ($isLocalAuthor) {
            $repliedToAuthor = $comment->parent?->actor ?? $comment->post->actor;
            $this->delivery->deliverContent($comment, ActivitySerializer::delete($comment), [$repliedToAuthor]);
        }
    }
}
