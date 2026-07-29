<?php

namespace App\Http\Controllers;

use App\Application\Queries\FeedQuery;
use App\Application\Queries\PopularRemoteActorsQuery;
use App\Domain\Posts\Post;
use Illuminate\Contracts\View\View;

/**
 * Sezione "Mondo": una finestra su cio' che arriva dal resto del fediverso
 * verso questa istanza (vedi {@see FeedQuery::world()} per i limiti di
 * questa vista), con qualche account remoto da scoprire
 * ({@see PopularRemoteActorsQuery}).
 */
class WorldController extends Controller
{
    public function __construct(
        private readonly FeedQuery $feedQuery,
        private readonly PopularRemoteActorsQuery $popularRemoteActorsQuery,
    ) {}

    public function index(): View
    {
        $viewer = auth()->user()->actor;

        $posts = $this->feedQuery->world();
        Post::annotateViewerState($posts->getCollection(), $viewer);

        return view('world.index', [
            'posts' => $posts,
            'suggestedActors' => $this->popularRemoteActorsQuery->forViewer($viewer),
        ]);
    }
}
