<?php

namespace App\Http\Controllers;

use App\Application\Services\ReportManager;
use App\Domain\Posts\Post;
use App\Http\Requests\Moderation\StoreReportRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportManager $reportManager,
    ) {}

    public function create(Post $post): View
    {
        Gate::authorize('report', $post);

        abort_unless(
            Post::query()
                ->whereKey($post->id)
                ->where('status', Post::STATUS_PUBLISHED)
                ->visibleTo(auth()->user()->actor)
                ->exists(),
            404,
        );

        $post->loadMissing('actor.user.profile');

        return view('reports.create', [
            'post' => $post,
        ]);
    }

    public function store(StoreReportRequest $request, Post $post): RedirectResponse
    {
        Gate::authorize('report', $post);

        abort_unless(
            Post::query()
                ->whereKey($post->id)
                ->where('status', Post::STATUS_PUBLISHED)
                ->visibleTo($request->user()->actor)
                ->exists(),
            404,
        );

        $this->reportManager->reportPost($request->user(), $post, $request->validated());

        return redirect()
            ->route('posts.show', $post)
            ->with('status', __('openbook.reports.submitted'));
    }
}
