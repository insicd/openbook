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

final class MePostController extends Controller
{
    use RespondsWithConditionalRest;

    public function index(Request $request, FeedQuery $feeds): Response
    {
        $validator = Validator::make($request->query(), [
            'after' => ['sometimes', 'uuid'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', Rule::in(['postPublishedAt'])],
            'direction' => ['sometimes', Rule::in(['desc'])],
            'afterAt' => ['prohibited'], 'engine' => ['prohibited'],
            'cursor' => ['prohibited'], 'page' => ['prohibited'],
        ]);
        if ($validator->fails()) {
            $this->invalid($validator->errors()->toArray());
        }
        $values = $validator->validated();
        $viewer = $request->user()->actor;
        $query = $viewer->posts()->excludingPrivateMessages()->where('status', Post::STATUS_PUBLISHED)->visibleTo($viewer);
        $cursor = null;
        if (isset($values['after'])) {
            $anchor = (clone $query)->whereKey($values['after'])->first(['posts.id', 'posts.published_at']);
            if ($anchor === null) {
                $this->invalid(['after' => ['The reference post is unavailable in this collection.']]);
            }
            $cursor = FeedCursor::fromPost($anchor, false);
        }
        $limit = (int) ($values['limit'] ?? 20);
        $page = $feeds->paginatePublishedQuery($query->with(Post::CARD_RELATIONS), $cursor, $limit);
        Post::annotateViewerState($page->getCollection(), $viewer);
        $items = $page->getCollection()->map(fn (Post $post): array => (new PostResource($post))->resolve($request));
        $response = response()->json($items->values()->all());
        if ($page->hasMorePages()) {
            $parameters = ['limit' => $limit, 'sort' => 'postPublishedAt', 'direction' => 'desc',
                'after' => $page->getCollection()->last()->id];
            $response->headers->set('Link', '<'.$request->url().'?'.http_build_query($parameters).'>; rel="next"');
        }

        return $this->conditionalResponse($request, $response);
    }

    private function invalid(array $errors): never
    {
        throw new HttpResponseException(response()->json([
            'type' => 'about:blank', 'title' => 'Unprocessable Content', 'status' => 422,
            'errors' => (object) $errors,
        ], 422, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']));
    }
}
