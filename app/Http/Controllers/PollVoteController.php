<?php

namespace App\Http\Controllers;

use App\Application\Services\LocalPollVoter;
use App\Application\Services\RemotePollVoter;
use App\Domain\Polls\Poll;
use App\Domain\Posts\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PollVoteController extends Controller
{
    public function __invoke(Request $request, Post $post, RemotePollVoter $remoteVoter, LocalPollVoter $localVoter): RedirectResponse|JsonResponse
    {
        $actor = $request->user()->actor;
        abort_unless($post->isPublished() && Post::whereKey($post->id)->visibleTo($actor)->exists(), 404);
        $poll = $post->poll()->firstOrFail();
        $bag = 'poll-'.$post->id;
        $data = $request->validateWithBag($bag, [
            'choices' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'choices.*' => ['required', 'uuid', 'distinct'],
            'options_version' => ['required', 'string', 'regex:/\\A[a-f0-9]{64}\\z/'],
        ], [
            'choices.required' => __('openbook.polls.choose'),
            'choices.min' => __('openbook.polls.choose'),
        ]);
        try {
            $voter = $post->isRemote() ? $remoteVoter : $localVoter;
            $voter->vote($actor, $poll, $data['choices'], $data['options_version']);
        } catch (ValidationException $exception) {
            $exception->errorBag = $bag;
            throw $exception;
        }

        if ($request->expectsJson()) {
            $post->load(['actor.endpoints', 'poll.options']);
            Poll::annotateViewerState([$post->poll], $actor);

            return response()->json([
                'html' => view('posts._poll', ['post' => $post, 'embed' => false])->render(),
                'message' => __('openbook.polls.confirmed'),
            ])->header('Cache-Control', 'private, no-store');
        }

        return back()->withFragment('poll-'.$post->id)->with('status', __('openbook.polls.confirmed'));
    }
}
