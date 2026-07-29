<?php

namespace App\Http\Controllers;

use App\Application\Services\PostComposer;
use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\ActivitySerializer;
use App\Federation\Serialization\NoteSerializer;
use App\Http\Requests\Posts\StorePostRequest;
use App\Http\Support\ActivityPubNegotiation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class PostController extends Controller
{
    public function __construct(
        private readonly PostComposer $postComposer,
        private readonly ActivityDelivery $delivery,
    ) {}

    public function store(StorePostRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['images'] = $request->file('images', []);

        $this->postComposer->compose($request->user()->actor, $data);

        return redirect()->route('feed.index')->with('status', 'Il tuo post e stato pubblicato.');
    }

    /**
     * Identificatore canonico del post: serve l'HTML oppure, tramite content
     * negotiation, l'oggetto ActivityStreams "Note" (o "Tombstone" se il
     * post e' stato eliminato).
     */
    public function show(Request $request, Post $post): View|JsonResponse
    {
        $wantsActivityPub = ActivityPubNegotiation::wantsActivityPub($request);
        $viewer = $wantsActivityPub ? null : auth()->user()?->actor;

        abort_unless(
            Post::query()->whereKey($post->id)->visibleTo($viewer)->exists(),
            404,
        );

        if ($wantsActivityPub) {
            return ActivityPubNegotiation::response(
                $post->isPublished() ? NoteSerializer::forPost($post) : NoteSerializer::tombstoneForPost($post)
            );
        }

        $post->load(['actor.user.profile', 'media.thumbnail', 'hashtags']);
        Post::annotateViewerState([$post], $viewer);

        $comments = Comment::query()
            ->where('post_id', $post->id)
            ->with('actor.user.profile')
            ->orderBy('created_at')
            ->get();

        Comment::annotateViewerState($comments, $viewer);

        return view('posts.show', [
            'post' => $post,
            'commentTree' => $this->buildCommentTree($comments),
        ]);
    }

    public function destroy(Post $post): RedirectResponse
    {
        Gate::authorize('delete', $post);

        $post->load('mentions.actor', 'actor');
        $isLocalAuthor = $post->actor->isLocal();

        $post->update([
            'title' => null,
            'content_warning' => null,
            'body' => '',
            'status' => Post::STATUS_DELETED,
        ]);

        if ($isLocalAuthor) {
            $this->delivery->deliverContent($post, ActivitySerializer::delete($post));
        }

        return redirect()->route('feed.index')->with('status', 'Post eliminato.');
    }

    /**
     * Raggruppa un elenco piatto di commenti in un albero, mantenendo
     * l'ordine cronologico a ogni livello. Per questo milestone l'intero
     * thread viene caricato in una sola query: il caricamento progressivo
     * per discussioni molto grandi e' rimandato a una fase successiva.
     *
     * @param  Collection<int, Comment>  $comments
     * @return array<int, array{comment: Comment, children: array<int, mixed>}>
     */
    private function buildCommentTree($comments): array
    {
        $byParent = $comments->groupBy(fn (Comment $comment) => $comment->parent_comment_id ?? 'root');

        $build = function (string $parentKey) use (&$build, $byParent): array {
            return $byParent->get($parentKey, collect())
                ->map(fn (Comment $comment) => [
                    'comment' => $comment,
                    'children' => $build($comment->id),
                ])
                ->all();
        };

        return $build('root');
    }
}
