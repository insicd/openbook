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
        if ($request->ajax()) {
            $viewer = auth()->user()->actor;
            $posts = $this->feedQuery->world(FeedCursor::fromRequest($request));
            Post::annotateViewerState($posts->getCollection(), $viewer);

            return view('posts._feed', [
                'posts' => $posts,
                'emptyMessage' => __('openbook.world.empty'),
                'asyncFeed' => true,
                'fragment' => true,
            ]);
        }

        return view('world.index');
    }

    public function suggestions(): View
    {
        $preview = $this->popularRemoteActorsQuery->forViewer(
            auth()->user()->actor,
            PopularRemoteActorsQuery::PREVIEW_LIMIT + 1,
        );

        return view('world._suggestions', [
            'suggestedActors' => $preview->take(PopularRemoteActorsQuery::PREVIEW_LIMIT),
            'suggestedActorsHasMore' => $preview->count() > PopularRemoteActorsQuery::PREVIEW_LIMIT,
        ]);
    }

    /**
     * Elenco completo degli account remoti suggeriti (oltre i 5 in anteprima
     * sulla pagina Mondo).
     */
    public function discover(Request $request): View
    {
        $viewer = auth()->user()->actor;
        $suggestedActors = $this->popularRemoteActorsQuery->paginateForViewer($viewer);

        if ($request->ajax() && $suggestedActors->currentPage() > 1) {
            return view('world._discover_list', [
                'suggestedActors' => $suggestedActors,
                'fragment' => true,
            ]);
        }

        return view('world.discover', [
            'suggestedActors' => $suggestedActors,
        ]);
    }
}
