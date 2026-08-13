<?php

namespace App\Http\Controllers;

use App\Application\Services\AnnounceManager;
use App\Domain\Posts\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AnnounceController extends Controller
{
    public function __construct(
        private readonly AnnounceManager $announces,
    ) {}

    public function store(Request $request, Post $post): RedirectResponse|JsonResponse
    {
        $this->announces->announce(auth()->user()->actor, $post);

        return $this->respond($request, $post->fresh(), announced: true);
    }

    public function destroy(Request $request, Post $post): RedirectResponse|JsonResponse
    {
        $this->announces->unannounce(auth()->user()->actor, $post);

        return $this->respond($request, $post->fresh(), announced: false);
    }

    private function respond(Request $request, Post $post, bool $announced): RedirectResponse|JsonResponse
    {
        $count = (int) $post->announces_count;

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'announced' => $announced,
                'announces_count' => $count,
                'label' => __($announced ? 'openbook.actions.announced' : 'openbook.actions.announce', [
                    'count' => $count,
                ]),
            ]);
        }

        return back()->withFragment('post-'.$post->id);
    }
}
