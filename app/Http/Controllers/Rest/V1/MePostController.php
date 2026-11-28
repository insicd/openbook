<?php

namespace App\Http\Controllers\Rest\V1;

use App\Application\Queries\FeedQuery;
use App\Http\Controllers\Concerns\RespondsWithPublishedPosts;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MePostController extends Controller
{
    use RespondsWithPublishedPosts;

    public function index(Request $request, FeedQuery $feeds): Response
    {
        return $this->publishedPostsResponse($request, $feeds, $request->user()->actor->posts(), $request->url());
    }
}
