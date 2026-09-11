<?php

namespace App\Http\Controllers;

use App\Application\Services\PostComposer;
use App\Application\Services\PostPublicationStager;
use App\Application\Services\QuotedPostResolver;
use App\Domain\Comments\Comment;
use App\Domain\Comments\CommentThread;
use App\Domain\Posts\PendingPostPublication;
use App\Domain\Posts\Post;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Posts\RemotePostRefresher;
use App\Federation\Replies\RemoteRepliesFetcher;
use App\Federation\Serialization\ActivitySerializer;
use App\Federation\Serialization\NoteSerializer;
use App\Http\Requests\Posts\StorePostRequest;
use App\Http\Requests\Posts\UpdatePostRequest;
use App\Http\Support\ActivityPubNegotiation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class PostController extends Controller
{
    public function __construct(
        private readonly PostComposer $postComposer,
        private readonly ActivityDelivery $delivery,
        private readonly RemoteRepliesFetcher $remoteRepliesFetcher,
        private readonly RemotePostRefresher $remotePostRefresher,
        private readonly QuotedPostResolver $quotedPostResolver,
    ) {}

    public function store(StorePostRequest $request): RedirectResponse|JsonResponse
    {
        $data = $request->validated();
        $data['images'] = $request->file('images', []);

        if ($this->containsVideo($data['images'])) {
            app(PostPublicationStager::class)->stage($request->user()->actor, $data);

            return $this->storeResponse(
                $request,
                route('feed.index'),
                __('openbook.posts.video_queued'),
            );
        }

        $post = $this->postComposer->compose($request->user()->actor, $data);

        if ($post->community_id !== null) {
            $post->loadMissing('community');

            return $this->storeResponse(
                $request,
                route('communities.show', $post->community),
                __('openbook.communities.post_published'),
            );
        }

        $addressedGroupId = $data['addressed_group_actor_id'] ?? null;

        if (is_string($addressedGroupId) && $addressedGroupId !== '') {
            return $this->storeResponse(
                $request,
                route('actors.show', $addressedGroupId),
                __('openbook.communities.remote_post_sent'),
            );
        }

        return $this->storeResponse(
            $request,
            route('posts.show', $post),
            __('openbook.posts.published'),
        );
    }

    private function storeResponse(Request $request, string $redirect, string $status): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            $request->session()->flash('status', $status);

            return response()->json(['redirect' => $redirect], 201);
        }

        return redirect()->to($redirect)->with('status', $status);
    }

    public function destroyPending(PendingPostPublication $publication): RedirectResponse
    {
        abort_unless($publication->actor?->user_id === auth()->id(), 403);

        $result = DB::transaction(function () use ($publication): string {
            $locked = PendingPostPublication::query()->lockForUpdate()->find($publication->id);

            if ($locked === null || $locked->status === PendingPostPublication::STATUS_PUBLISHED) {
                return 'missing';
            }

            if ($locked->status === PendingPostPublication::STATUS_PROCESSING) {
                return 'processing';
            }

            $locked->delete();

            return 'deleted';
        });

        abort_if($result === 'missing', 404);

        if ($result === 'processing') {
            return back()->with('error', __('openbook.posts.video_processing_cannot_delete'));
        }

        $directory = 'post-publication/'.$publication->id;
        $publication->delete();
        Storage::disk('local')->deleteDirectory($directory);

        return back()->with('status', __('openbook.posts.video_deleted'));
    }

    /** @param array<int, UploadedFile> $files */
    private function containsVideo(array $files): bool
    {
        $videoMimes = (array) config('openbook.video.allowed_mime_types');

        return collect($files)->contains(
            fn ($file) => in_array(strtolower((string) $file->getMimeType()), $videoMimes, true),
        );
    }

    public function edit(Post $post): View
    {
        Gate::authorize('update', $post);

        $post->load(Post::CARD_RELATIONS);

        return view('posts.edit', [
            'post' => $post,
        ]);
    }

    public function update(UpdatePostRequest $request, Post $post): RedirectResponse
    {
        $data = $request->validated();
        $data['images'] = $request->file('images', []);

        $this->postComposer->update($request->user()->actor, $post, $data);

        return redirect()
            ->route('posts.show', $post)
            ->with('status', __('openbook.posts.updated'));
    }

    /**
     * Avvia una citazione: porta l'utente alla home con il composer gia'
     * predisposto sul post da citare (visibile solo se ha diritto a vederlo).
     */
    public function quote(Post $post): RedirectResponse
    {
        $viewer = auth()->user()->actor;

        abort_unless(
            Post::query()
                ->whereKey($post->id)
                ->where('status', Post::STATUS_PUBLISHED)
                ->visibleTo($viewer)
                ->exists(),
            404,
        );

        return redirect()->route('feed.index', ['quote' => $post->id]);
    }

    /**
     * Condividi il post in un messaggio privato: apre /messaggi con la
     * citazione gia' predisposta. Stessi vincoli di visibilita' della quote
     * pubblica; i messaggi diretti non si inoltrano.
     */
    public function shareToUser(Post $post): RedirectResponse
    {
        $viewer = auth()->user()->actor;

        abort_unless($this->quotedPostResolver->resolveForShare($viewer, $post->id) !== null, 404);

        return redirect()->route('messages.index', ['quote' => $post->id]);
    }

    /**
     * Identificatore canonico del post: serve l'HTML oppure, tramite content
     * negotiation, l'oggetto ActivityStreams "Note" (o "Tombstone" se il
     * post e' stato eliminato).
     */
    public function show(Request $request, Post $post): View|JsonResponse|RedirectResponse
    {
        $wantsActivityPub = ActivityPubNegotiation::wantsActivityPub($request);
        $viewer = $wantsActivityPub ? null : auth()->user()?->actor;

        abort_unless(
            Post::query()->whereKey($post->id)->visibleTo($viewer)->exists(),
            404,
        );

        if (! $wantsActivityPub && $post->isDirectMessage() && $viewer !== null) {
            $post->loadMissing('conversation');

            if ($post->conversation !== null && $post->conversation->involves($viewer)) {
                return redirect()->route('messages.show', $post->conversation_id);
            }
        }

        if ($wantsActivityPub) {
            return ActivityPubNegotiation::response(
                $post->isPublished() ? NoteSerializer::forPost($post) : NoteSerializer::tombstoneForPost($post)
            );
        }

        // Post remoto: recupera (con TTL) le replies pubbliche e i totali
        // likes/shares dalla Note originale, cosi' commenti e contatori
        // delle card restano allineati all'origine.
        if ($post->isRemote()) {
            try {
                $this->remoteRepliesFetcher->fetchReplies($post);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        $post->load(Post::CARD_RELATIONS);
        Post::annotateViewerState([$post], $viewer);

        $comments = Comment::query()
            ->where('post_id', $post->id)
            ->with(['actor.user.profile', 'media.thumbnail', 'parent.actor.user.profile'])
            ->orderBy('created_at')
            ->get();

        Comment::annotateViewerState($comments, $viewer);

        return view('posts.show', [
            'post' => $post,
            'commentTree' => CommentThread::tree($comments),
        ]);
    }

    /**
     * Recupero on-demand di un post remoto: aggiorna la Note e forza il
     * fetch delle replies, ignorando il TTL della sola apertura pagina.
     */
    public function fetchUpdates(Post $post): RedirectResponse
    {
        $viewer = auth()->user()->actor;

        abort_unless(
            $post->isRemote()
            && $post->isPublished()
            && Post::query()->whereKey($post->id)->visibleTo($viewer)->exists(),
            404,
        );

        $this->remotePostRefresher->refresh($post);

        return back()->with('status', __('openbook.posts.updates_fetched'));
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
}
