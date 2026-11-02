<?php

namespace App\Http\Controllers\Rest\V1;

use App\Application\Queries\FeedCursor;
use App\Application\Queries\FeedQuery;
use App\Application\Services\LocalContentDeleter;
use App\Application\Services\PostComposer;
use App\Application\Services\ReactionManager;
use App\Domain\Posts\Post;
use App\Federation\Replies\RemoteRepliesFetcher;
use App\Http\Controllers\Concerns\RespondsWithConditionalRest;
use App\Http\Controllers\Concerns\ValidatesRestInput;
use App\Http\Controllers\Controller;
use App\Http\Resources\Rest\V1\PostResource;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class PostController extends Controller
{
    use RespondsWithConditionalRest;
    use ValidatesRestInput;

    public function store(Request $request, PostComposer $composer): Response
    {
        $values = $this->postInput($request);
        $author = $request->user()->actor;
        $post = $composer->compose($author, [
            'body' => $values['postContent'],
            'visibility' => $values['postVisibility'],
        ]);
        $post->refresh()->load(Post::CARD_RELATIONS);
        Post::annotateViewerState([$post], $author);
        $response = (new PostResource($post))->toResponse($request);
        $response->setStatusCode(201);
        $response->headers->set('Location', route('rest.v1.posts.show', ['postId' => $post->id]));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    public function update(Request $request, string $postId, PostComposer $composer): Response
    {
        $values = $this->postInput($request, partial: true);
        $post = DB::transaction(function () use ($request, $postId, $composer, $values): Post {
            $post = $this->ownedPost($request, $postId);
            Gate::authorize('update', $post);
            abort_if(
                ! in_array($post->visibility, [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_UNLISTED, Post::VISIBILITY_FOLLOWERS], true)
                || $post->community_id !== null || $post->quoted_post_id !== null
                || $post->quoted_event_id !== null || $post->quoted_actor_id !== null
                || $post->media()->exists() || $post->location()->exists() || $post->poll()->exists(),
                409,
            );

            return $composer->update($request->user()->actor, $post, [
                'body' => $values['postContent'] ?? $post->body,
                'visibility' => $values['postVisibility'] ?? $post->visibility,
                'title' => $post->title,
                'content_warning' => $post->content_warning,
                'language' => $post->language,
            ]);
        });
        $post->refresh()->load(Post::CARD_RELATIONS);
        Post::annotateViewerState([$post], $request->user()->actor);
        $response = (new PostResource($post))->toResponse($request);
        $response->setStatusCode(200);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    public function destroy(Request $request, string $postId, LocalContentDeleter $deleter): Response
    {
        DB::transaction(function () use ($request, $postId, $deleter): void {
            $post = $this->ownedPost($request, $postId);
            Gate::authorize('delete', $post);
            $deleter->delete($request->user()->actor, $post);
        });

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    private function ownedPost(Request $request, string $postId): Post
    {
        abort_unless(Str::isUuid($postId), 404);
        $post = Post::query()->whereKey($postId)->where('actor_id', $request->user()->actor->id)
            ->excludingPrivateMessages()->where('status', Post::STATUS_PUBLISHED)
            ->with('actor')->lockForUpdate()->first();
        abort_if($post === null, 404);
        abort_if($post->isRemote() || ! $post->actor->isLocal(), 403);

        return $post;
    }

    private function postInput(Request $request, bool $partial = false): array
    {
        abort_unless($request->isJson(), 415);
        try {
            $payload = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(400);
        }
        if (! $payload instanceof \stdClass) {
            $this->invalid(['body' => ['The request body must be a JSON object.']]);
        }
        $data = $request->json()->all();
        $presence = $partial ? ['sometimes', 'required'] : ['required'];
        $validator = Validator::make($data, [
            'postContent' => [...$presence, 'string', 'regex:/\S/u', 'max:'.(int) config('openbook.posts.max_length')],
            'postVisibility' => [...$presence, 'string', Rule::in([Post::VISIBILITY_PUBLIC, Post::VISIBILITY_UNLISTED, Post::VISIBILITY_FOLLOWERS])],
        ]);
        $errors = $validator->errors()->toArray();
        if ($partial && $data === []) {
            $errors['body'] = ['At least one property must be supplied.'];
        }
        foreach (array_diff(array_keys($data), ['postContent', 'postVisibility']) as $field) {
            $errors[$field] = ['This field is not supported.'];
        }
        if ($errors !== []) {
            $this->invalid($errors);
        }

        return $validator->validated();
    }

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
}
