<?php

namespace App\Http\Controllers\Concerns;

use App\Application\Queries\FeedCursor;
use App\Application\Queries\FeedQuery;
use App\Domain\Posts\Post;
use App\Http\Resources\Rest\V1\PostResource;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

trait RespondsWithPublishedPosts
{
    use RespondsWithConditionalRest;
    use ValidatesRestInput;

    /** @param Relation<*, Post, *> $posts */
    protected function publishedPostsResponse(Request $request, FeedQuery $feeds, Relation $posts, string $url): Response
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
        $query = $posts->excludingPrivateMessages()->where('status', Post::STATUS_PUBLISHED)->visibleTo($viewer);
        $cursor = null;
        if (isset($values['after'])) {
            $anchor = (clone $query)->whereKey($values['after'])->first(['posts.id', 'posts.published_at']);
            if ($anchor === null) {
                $this->invalid(['after' => ['The reference post is unavailable in this collection.']]);
            }
            $cursor = FeedCursor::fromPost($anchor, false);
        }
        $limit = (int) ($values['limit'] ?? 20);
        $page = $feeds->paginatePublishedQuery($query->with(PostResource::RELATIONS), $cursor, $limit);
        Post::annotateViewerState($page->getCollection(), $viewer);
        $items = $page->getCollection()->map(fn (Post $post): array => (new PostResource($post))->resolve($request));
        $response = response()->json($items->values()->all());
        if ($page->hasMorePages()) {
            $parameters = ['limit' => $limit, 'sort' => 'postPublishedAt', 'direction' => 'desc',
                'after' => $page->getCollection()->last()->id];
            $response->headers->set('Link', '<'.$url.'?'.http_build_query($parameters).'>; rel="next"');
        }

        return $this->conditionalResponse($request, $response);
    }
}
