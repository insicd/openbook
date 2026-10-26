<?php

namespace App\Http\Controllers\Rest\V1;

use App\Application\Queries\FeedCursor;
use App\Application\Queries\FeedQuery;
use App\Domain\Posts\Post;
use App\Http\Controllers\Concerns\RespondsWithConditionalRest;
use App\Http\Controllers\Controller;
use App\Http\Resources\Rest\V1\PostResource;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class PostController extends Controller
{
    use RespondsWithConditionalRest;

    public function index(Request $request, FeedQuery $feeds): Response
    {
        $validator = Validator::make($request->query(), [
            'engine' => ['sometimes', Rule::in(['world'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'after' => ['sometimes', 'uuid'],
            'sort' => ['sometimes', Rule::in(['postPublishedAt'])],
            'direction' => ['sometimes', Rule::in(['desc'])],
            'cursor' => ['prohibited'],
            'page' => ['prohibited'],
        ]);
        if ($validator->fails()) {
            $this->invalid($validator->errors()->toArray());
        }
        $values = $validator->validated();
        $viewer = $request->user()->actor;
        $cursor = null;
        if (isset($values['after'])) {
            $anchor = Post::query()->visibleTo($viewer)->excludingPrivateMessages()
                ->where('status', Post::STATUS_PUBLISHED)->find($values['after'], ['posts.id', 'posts.published_at']);
            if ($anchor === null) {
                $this->invalid(['after' => ['The reference post is unavailable.']]);
            }
            $cursor = FeedCursor::fromPost($anchor, false);
        }
        $limit = (int) ($values['limit'] ?? 20);
        $page = $feeds->world($cursor, $limit);
        Post::annotateViewerState($page->getCollection(), $viewer);
        $response = response()->json(PostResource::collection($page->getCollection())->resolve($request));
        if ($page->hasMorePages()) {
            $query = http_build_query(['engine' => 'world', 'limit' => $limit,
                'sort' => 'postPublishedAt', 'direction' => 'desc', 'after' => $page->getCollection()->last()->id]);
            $response->headers->set('Link', '<'.$request->url().'?'.$query.'>; rel="next"');
        }

        return $this->conditionalResponse($request, $response);
    }

    private function invalid(array $errors): never
    {
        throw new HttpResponseException(response()->json([
            'type' => 'about:blank', 'title' => 'Unprocessable Content', 'status' => 422, 'errors' => $errors,
        ], 422, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']));
    }
}
