<?php

namespace App\Http\Controllers;

use App\Application\Services\CommentComposer;
use App\Application\Services\CommentSoftDeleter;
use App\Domain\Comments\Comment;
use App\Domain\Comments\CommentThread;
use App\Domain\Posts\Post;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\ActivitySerializer;
use App\Federation\Serialization\NoteSerializer;
use App\Http\Requests\Comments\StoreCommentRequest;
use App\Http\Support\ActivityPubNegotiation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CommentController extends Controller
{
    public function __construct(
        private readonly CommentComposer $commentComposer,
        private readonly CommentSoftDeleter $commentSoftDeleter,
        private readonly ActivityDelivery $delivery,
    ) {}

    /**
     * Identificatore canonico del commento: un browser vede il thread
     * centrato su questo commento (padri sopra, figli sotto, un solo
     * livello di indentazione); un client ActivityPub riceve la Note
     * con inReplyTo.
     */
    public function show(Request $request, Comment $comment): View|RedirectResponse|JsonResponse
    {
        $comment->loadMissing('post');

        $wantsActivityPub = ActivityPubNegotiation::wantsActivityPub($request);
        $viewer = $wantsActivityPub ? null : auth()->user()?->actor;
        $post = $comment->post;

        abort_unless(
            $post !== null
            && Post::query()->whereKey($post->id)->visibleTo($viewer)->exists(),
            404,
        );

        if ($wantsActivityPub) {
            return ActivityPubNegotiation::response(
                $comment->isPublished() ? NoteSerializer::forComment($comment) : NoteSerializer::tombstoneForComment($comment)
            );
        }

        if (! $comment->isPublished()) {
            return redirect(route('posts.show', $post).'#commenti');
        }

        $post->load(Post::CARD_RELATIONS);
        Post::annotateViewerState([$post], $viewer);

        $comments = Comment::query()
            ->where('post_id', $post->id)
            ->with(['actor.user.profile', 'media.thumbnail', 'parent.actor.user.profile'])
            ->orderBy('created_at')
            ->get();

        Comment::annotateViewerState($comments, $viewer);

        $tree = CommentThread::tree($comments);
        $focused = CommentThread::findNode($tree, $comment->id);

        abort_unless($focused !== null, 404);

        $ancestors = CommentThread::publishedAncestors($comment, $comments->keyBy('id'));

        return view('comments.show', [
            'post' => $post,
            'comment' => $comment,
            'ancestors' => $ancestors,
            'focusedNode' => $focused,
        ]);
    }

    public function store(StoreCommentRequest $request, Post $post): RedirectResponse
    {
        $viewer = $request->user()->actor;

        abort_unless(
            Post::query()->whereKey($post->id)->visibleTo($viewer)->exists(),
            404,
        );

        $data = $request->validated();

        $parent = filled($data['parent_comment_id'] ?? null)
            ? Comment::query()->findOrFail($data['parent_comment_id'])
            : null;

        $images = $request->file('images', []);
        if (! is_array($images)) {
            $images = $images !== null ? [$images] : [];
        }

        $comment = $this->commentComposer->compose(
            $viewer,
            $post,
            $data['body'],
            $parent,
            array_values($images),
            $data['alt_texts'] ?? [],
        );

        return redirect(route('posts.show', $post).'#commento-'.$comment->id);
    }

    public function destroy(Comment $comment): RedirectResponse
    {
        Gate::authorize('delete', $comment);

        $comment->load('mentions.actor', 'actor', 'post', 'parent.actor');
        $isLocalAuthor = $comment->actor->isLocal();

        $this->commentSoftDeleter->delete($comment);

        if ($isLocalAuthor) {
            $repliedToAuthor = $comment->parent?->actor ?? $comment->post->actor;

            $this->delivery->deliverContent($comment, ActivitySerializer::delete($comment), [$repliedToAuthor]);
        }

        return redirect()->route('posts.show', $comment->post_id)->with('status', 'Commento eliminato.');
    }
}
