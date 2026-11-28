<?php

namespace App\Http\Controllers\Rest\V1;

use App\Application\Queries\FeedQuery;
use App\Domain\Posts\Hashtag;
use App\Http\Controllers\Concerns\RespondsWithPublishedPosts;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class HashtagPostController extends Controller
{
    use RespondsWithPublishedPosts;

    public function index(Request $request, string $tag, FeedQuery $feeds): Response
    {
        $name = Hashtag::normalize($tag);
        abort_unless(Hashtag::isValidName($name), 404);
        $hashtag = Hashtag::query()->where('name', $name)->first();
        abort_if($hashtag === null, 404);

        return $this->publishedPostsResponse(
            $request,
            $feeds,
            $hashtag->posts(),
            route('rest.v1.hashtags.posts', ['tag' => $hashtag->name]),
        );
    }
}
