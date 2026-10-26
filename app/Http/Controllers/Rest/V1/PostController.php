<?php

namespace App\Http\Controllers\Rest\V1;

use App\Application\Queries\FeedCursor;
use App\Application\Queries\FeedQuery;
use App\Application\Services\ReactionManager;
use App\Domain\Posts\Post;
use App\Federation\Replies\RemoteRepliesFetcher;
use App\Http\Controllers\Concerns\RespondsWithConditionalRest;
use App\Http\Controllers\Controller;
use App\Http\Resources\Rest\V1\PostResource;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class PostController extends Controller
{
    use RespondsWithConditionalRest;

    public function show(Request $request, string $postId, RemoteRepliesFetcher $replies): Response
    {
        $post = $this->visiblePost($request, $postId);
        if ($post->isRemote()) {
            try {
                $replies->fetchReplies($post);
            } catch (\Throwable $exception) {
                report($exception);
            }
            $post = $this->visiblePost($request, $postId);
        }
        $post->load(Post::CARD_RELATIONS);
        Post::annotateViewerState([$post], $request->user()->actor);

        return $this->conditionalResponse($request, (new PostResource($post))->toResponse($request));
    }

    public function like(Request $request, string $postId, ReactionManager $reactions): Response
    {
        $post = $this->visiblePost($request, $postId);
        $like = $reactions->like($request->user()->actor, $post);
        if ($like->wasRecentlyCreated) {
            return response('', 201, ['Location' => $request->url(), 'Cache-Control' => 'no-store']);
        }

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    public function unlike(Request $request, string $postId, ReactionManager $reactions): Response
    {
        $post = $this->visiblePost($request, $postId);
        $reactions->unlike($request->user()->actor, $post);

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    private function visiblePost(Request $request, string $postId): Post
    {
        abort_unless(Str::isUuid($postId), 404);
        $post = Post::query()->whereKey($postId)->visibleTo($request->user()->actor)
            ->excludingPrivateMessages()->where('status', Post::STATUS_PUBLISHED)->first();
        abort_if($post === null, 404);

        return $post;
    }

    public function index(Request $request, FeedQuery $feeds): Response
    {
        $engine = $request->query('engine', 'world');
        $timeline = $engine === 'timeline';
        $sort = $timeline ? 'postTimelineAt' : 'postPublishedAt';
        $validator = Validator::make($request->query(), [
            'engine' => ['sometimes', Rule::in(['world', 'timeline'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'after' => [Rule::requiredIf($timeline && $request->has('afterAt')), 'uuid'],
            'afterAt' => [Rule::requiredIf($timeline && $request->has('after')), Rule::prohibitedIf(! $timeline),
                'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D'],
            'sort' => ['sometimes', Rule::in([$sort])],
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
        if ($timeline && isset($values['after'])) {
            $cursor = new FeedCursor(Carbon::parse($values['afterAt'])->setTimezone(config('app.timezone'))->toDateTimeString(), $values['after']);
        } elseif (isset($values['after'])) {
            $anchor = Post::query()->visibleTo($viewer)->excludingPrivateMessages()
                ->where('status', Post::STATUS_PUBLISHED)->find($values['after'], ['posts.id', 'posts.published_at']);
            if ($anchor === null) {
                $this->invalid(['after' => ['The reference post is unavailable.']]);
            }
            $cursor = FeedCursor::fromPost($anchor, false);
        }
        $limit = (int) ($values['limit'] ?? 20);
        $page = $timeline ? $feeds->forActor($viewer, $cursor, $limit) : $feeds->world($cursor, $limit);
        Post::annotateViewerState($page->getCollection(), $viewer);
        $items = $page->getCollection()->map(function (Post $post) use ($request, $timeline): array {
            $resource = new PostResource($post);
            if ($timeline) {
                $resource->withTimelineContext();
            }

            return $resource->resolve($request);
        });
        $response = response()->json($items->values()->all());
        if ($page->hasMorePages()) {
            $last = $page->getCollection()->last();
            $parameters = ['engine' => $engine, 'limit' => $limit,
                'sort' => $sort, 'direction' => 'desc', 'after' => $last->id];
            if ($timeline) {
                $parameters['afterAt'] = ($last->shared_at ?? $last->published_at)->toISOString();
            }
            $query = http_build_query($parameters);
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
