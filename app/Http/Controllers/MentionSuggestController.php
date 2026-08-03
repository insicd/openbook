<?php

namespace App\Http\Controllers;

use App\Application\Queries\MentionSuggestQuery;
use App\Federation\Actors\Actor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MentionSuggestController extends Controller
{
    public function __construct(
        private readonly MentionSuggestQuery $suggestions,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $prefix = (string) $request->query('q', '');
        $viewer = $request->user()?->actor;

        $actors = $this->suggestions->forPrefix($prefix, $viewer);

        return response()->json([
            'suggestions' => $actors->map(fn (Actor $actor) => [
                'insert' => $actor->isLocal()
                    ? '@'.$actor->preferred_username.' '
                    : '@'.$actor->handle().' ',
                'handle' => $actor->isLocal()
                    ? $actor->preferred_username
                    : $actor->handle(),
                'display_name' => $actor->displayName(),
                'avatar_url' => $actor->avatarUrl(),
                'is_local' => $actor->isLocal(),
            ])->values(),
        ]);
    }
}
