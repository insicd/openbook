<?php

namespace App\Http\Controllers;

use App\Application\Services\CommentComposer;
use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\ActivitySerializer;
use App\Federation\Serialization\NoteSerializer;
use App\Http\Requests\Comments\StoreCommentRequest;
use App\Http\Support\ActivityPubNegotiation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CommentController extends Controller
{
    public function __construct(
        private readonly CommentComposer $commentComposer,
        private readonly ActivityDelivery $delivery,
    ) {}

    /**
     * Identificatore canonico del commento (sezione 17 del design): un
     * browser viene rimandato al permalink nella pagina del post, un client
     * ActivityPub riceve l'oggetto "Note" con "inReplyTo".
     */
    public function show(Request $request, Comment $comment): RedirectResponse|JsonResponse
    {
        $comment->loadMissing('post');

        $wantsActivityPub = ActivityPubNegotiation::wantsActivityPub($request);
        $viewer = $wantsActivityPub ? null : auth()->user()?->actor;

        abort_unless(
            Post::query()->whereKey($comment->post_id)->visibleTo($viewer)->exists(),
            404,
        );

        if ($wantsActivityPub) {
            return ActivityPubNegotiation::response(
                $comment->isPublished() ? NoteSerializer::forComment($comment) : NoteSerializer::tombstoneForComment($comment)
            );
        }

        return redirect(route('posts.show', $comment->post_id).'#commento-'.$comment->id);
    }

    public function store(StoreCommentRequest $request, Post $post): RedirectResponse
    {
        $data = $request->validated();

        $parent = filled($data['parent_comment_id'] ?? null)
            ? Comment::query()->findOrFail($data['parent_comment_id'])
            : null;

        $comment = $this->commentComposer->compose($request->user()->actor, $post, $data['body'], $parent);

        return redirect(route('posts.show', $post).'#commento-'.$comment->id);
    }

    public function destroy(Comment $comment): RedirectResponse
    {
        Gate::authorize('delete', $comment);

        $comment->load('mentions.actor', 'actor', 'post', 'parent.actor');
        $isLocalAuthor = $comment->actor->isLocal();

        $comment->update([
            'body' => '',
            'status' => Comment::STATUS_DELETED,
        ]);

        if ($isLocalAuthor) {
            $repliedToAuthor = $comment->parent?->actor ?? $comment->post->actor;

            $this->delivery->deliverContent($comment, ActivitySerializer::delete($comment), [$repliedToAuthor]);
        }

        return redirect()->route('posts.show', $comment->post_id)->with('status', 'Commento eliminato.');
    }
}
