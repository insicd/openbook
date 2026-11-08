<?php

namespace App\Http\Controllers\Rest\V1;

use App\Application\Services\CommentComposer;
use App\Application\Services\LocalContentDeleter;
use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use App\Federation\Replies\RemoteRepliesFetcher;
use App\Http\Controllers\Concerns\RespondsWithConditionalRest;
use App\Http\Controllers\Concerns\ValidatesRestInput;
use App\Http\Controllers\Controller;
use App\Http\Resources\Rest\V1\CommentResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class PostCommentController extends Controller
{
    use RespondsWithConditionalRest;
    use ValidatesRestInput;

    private const RELATIONS = ['actor.user.profile', 'media.thumbnail'];

    public function index(Request $request, string $postId, RemoteRepliesFetcher $replies): Response
    {
        $post = $this->readPost($request, $postId, $replies);
        $validator = Validator::make($request->query(), [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'after' => ['sometimes', 'uuid'],
            'sort' => ['sometimes', Rule::in(['commentPublishedAt'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['prohibited'], 'cursor' => ['prohibited'], 'afterAt' => ['prohibited'],
        ]);
        if ($validator->fails()) {
            $this->invalid($validator->errors()->toArray());
        }
        $values = $validator->validated();
        $query = Comment::query()->where('post_id', $post->id)->where('status', Comment::STATUS_PUBLISHED);
        $direction = $values['direction'] ?? 'asc';
        if (isset($values['after'])) {
            $anchor = (clone $query)->find($values['after']);
            if ($anchor === null) {
                $this->invalid(['after' => ['The reference comment is unavailable.']]);
            }
            $operator = $direction === 'asc' ? '>' : '<';
            $query->where(function ($query) use ($anchor, $operator): void {
                $query->where('created_at', $operator, $anchor->created_at)
                    ->orWhere(function ($query) use ($anchor, $operator): void {
                        $query->where('created_at', $anchor->created_at)->where('id', $operator, $anchor->id);
                    });
            });
        }
        $limit = (int) ($values['limit'] ?? 20);
        $items = $query->with(self::RELATIONS)->reorder()->orderBy('created_at', $direction)
            ->orderBy('id', $direction)->limit($limit + 1)->get();
        $hasMore = $items->count() > $limit;
        $items = $items->take($limit);
        Comment::annotateViewerState($items, $request->user()->actor);
        $response = response()->json(CommentResource::collection($items)->resolve($request));
        if ($hasMore) {
            $parameters = ['limit' => $limit, 'sort' => 'commentPublishedAt',
                'direction' => $direction, 'after' => $items->last()->id];
            $response->headers->set('Link', '<'.$request->url().'?'.http_build_query($parameters).'>; rel="next"');
        }

        return $this->conditionalResponse($request, $response);
    }

    public function show(Request $request, string $postId, string $commentId, RemoteRepliesFetcher $replies): Response
    {
        $post = $this->readPost($request, $postId, $replies);
        $comment = $this->comment($post, $commentId);

        return $this->conditionalResponse($request, $this->resourceResponse($request, $comment));
    }

    public function store(Request $request, string $postId, CommentComposer $composer): Response
    {
        $values = $this->input($request);
        $comment = DB::transaction(function () use ($request, $postId, $composer, $values): Comment {
            $post = $this->visiblePost($request, $postId, lock: true);
            abort_if($post->visibility === Post::VISIBILITY_DIRECT, 409);
            $parent = null;
            if (isset($values['commentParentId'])) {
                $parent = Comment::query()->where('post_id', $post->id)->where('status', Comment::STATUS_PUBLISHED)
                    ->whereKey($values['commentParentId'])->lockForUpdate()->first();
                if ($parent === null) {
                    $this->invalid(['commentParentId' => ['The parent comment is unavailable.']]);
                }
            }

            return $composer->compose($request->user()->actor, $post, $values['commentContent'], $parent);
        });
        $response = $this->resourceResponse($request, $comment, 201);
        $response->headers->set('Location', route('rest.v1.comments.show', [
            'postId' => $postId, 'commentId' => $comment->id,
        ]));

        return $response;
    }

    public function update(Request $request, string $postId, string $commentId, CommentComposer $composer): Response
    {
        $values = $this->input($request, partial: true);
        $comment = DB::transaction(function () use ($request, $postId, $commentId, $composer, $values): Comment {
            $post = $this->visiblePost($request, $postId, lock: true);
            $comment = $this->comment($post, $commentId, $request);
            Gate::authorize('update', $comment);

            return $composer->update($request->user()->actor, $comment, $values['commentContent']);
        });

        return $this->resourceResponse($request, $comment);
    }

    public function destroy(Request $request, string $postId, string $commentId, LocalContentDeleter $deleter): Response
    {
        DB::transaction(function () use ($request, $postId, $commentId, $deleter): void {
            $post = $this->visiblePost($request, $postId, lock: true);
            $comment = $this->comment($post, $commentId, $request);
            Gate::authorize('delete', $comment);
            $deleter->delete($request->user()->actor, $comment);
        });

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    private function visiblePost(Request $request, string $postId, bool $lock = false): Post
    {
        abort_unless(Str::isUuid($postId), 404);
        $query = Post::query()->whereKey($postId)->visibleTo($request->user()->actor)
            ->excludingPrivateMessages()->where('status', Post::STATUS_PUBLISHED);
        $post = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_if($post === null, 404);

        return $post;
    }

    private function readPost(Request $request, string $postId, RemoteRepliesFetcher $replies): Post
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

        return $post;
    }

    private function comment(Post $post, string $commentId, ?Request $owner = null): Comment
    {
        abort_unless(Str::isUuid($commentId), 404);
        $query = Comment::query()->where('post_id', $post->id)->whereKey($commentId)->where('status', Comment::STATUS_PUBLISHED);
        if ($owner !== null) {
            $query->where('actor_id', $owner->user()->actor->id)->lockForUpdate();
        }
        $comment = $query->with('actor')->first();
        abort_if($comment === null, 404);
        if ($owner !== null) {
            abort_if($comment->isRemote() || ! $comment->actor->isLocal(), 403);
        }

        return $comment;
    }

    private function resourceResponse(Request $request, Comment $comment, int $status = 200): Response
    {
        $comment->load(self::RELATIONS);
        Comment::annotateViewerState([$comment], $request->user()->actor);
        $response = (new CommentResource($comment))->toResponse($request);
        $response->setStatusCode($status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function input(Request $request, bool $partial = false): array
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
        $rules = ['commentContent' => ['required', 'string', 'regex:/\S/u',
            'max:'.(int) config('openbook.comments.max_length', 2000)]];
        if (! $partial) {
            $rules['commentParentId'] = ['sometimes', 'nullable', 'uuid'];
        }
        $validator = Validator::make($request->json()->all(), $rules);
        $errors = $validator->errors()->toArray();
        foreach (array_diff(array_keys((array) $payload), array_keys($rules)) as $field) {
            $errors[$field] = ['This field is not supported.'];
        }
        if ($errors !== []) {
            $this->invalid($errors);
        }

        return $validator->validated();
    }
}
