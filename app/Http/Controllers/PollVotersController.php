<?php

namespace App\Http\Controllers;

use App\Application\Queries\PollVotersQuery;
use App\Domain\Posts\Post;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class PollVotersController extends Controller
{
    public function __invoke(Post $post, PollVotersQuery $query, ?string $option = null): Response
    {
        abort_unless(! $post->isRemote() && $post->isPublished(), 404);
        Gate::authorize('viewPollVoters', $post);
        $poll = $post->poll()->with('options')->firstOrFail();
        $selected = $option === null ? $poll->options->first() : $poll->options->firstWhere('id', $option);
        abort_if($selected === null, 404);
        $voters = $query->identifiable($selected)->paginate(PollVotersQuery::PER_PAGE)->withQueryString();
        $archivedCount = max(0, $query->total($selected) - $voters->total());

        return response()->view('polls.voters', [
            'post' => $post, 'poll' => $poll, 'selected' => $selected,
            'voters' => $voters, 'archivedCount' => $archivedCount,
        ])->header('Cache-Control', 'private, no-store');
    }
}
