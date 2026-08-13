<?php

namespace App\Http\Controllers;

use App\Application\Queries\FeedCursor;
use App\Application\Queries\FeedQuery;
use App\Application\Queries\PopularRemoteActorsQuery;
use App\Domain\Posts\Post;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

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

    public function index(Request $request): View
    {
        $viewer = auth()->user()->actor;

        $posts = $this->feedQuery->world(FeedCursor::fromRequest($request));
        Post::annotateViewerState($posts->getCollection(), $viewer);

        $preview = $this->popularRemoteActorsQuery->forViewer(
            $viewer,
            PopularRemoteActorsQuery::PREVIEW_LIMIT + 1,
        );

        return view('world.index', [
            'posts' => $posts,
            'suggestedActors' => $preview->take(PopularRemoteActorsQuery::PREVIEW_LIMIT),
            'suggestedActorsHasMore' => $preview->count() > PopularRemoteActorsQuery::PREVIEW_LIMIT,
        ]);
    }

    /**
     * Elenco completo degli account remoti suggeriti (oltre i 5 in anteprima
     * sulla pagina Mondo).
     */
    public function discover(): View
    {
        $viewer = auth()->user()->actor;

        return view('world.discover', [
            'suggestedActors' => $this->popularRemoteActorsQuery->paginateForViewer($viewer),
        ]);
    }
}
