<?php

namespace App\Http\Controllers;

use App\Application\Queries\ConversationListQuery;
use App\Application\Queries\MentionSuggestQuery;
use App\Application\Services\ConversationReadTracker;
use App\Application\Services\ConversationResolver;
use App\Application\Services\DirectMessagePolicy;
use App\Application\Services\MessageComposer;
use App\Application\Services\MessageRecipientResolver;
use App\Application\Services\QuotedActorResolver;
use App\Application\Services\QuotedPostResolver;
use App\Domain\Accounts\User;
use App\Domain\Messaging\Conversation;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Http\Presenters\ConversationMessagePresenter;
use App\Http\Requests\Messages\StartMessageRequest;
use App\Http\Requests\Messages\StoreMessageRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ConversationController extends Controller
{
    public function __construct(
        private readonly ConversationListQuery $conversations,
        private readonly ConversationReadTracker $readTracker,
        private readonly ConversationResolver $conversationResolver,
        private readonly MessageComposer $messageComposer,
        private readonly DirectMessagePolicy $policy,
        private readonly ConversationMessagePresenter $messagePresenter,
        private readonly MentionSuggestQuery $mentionSuggest,
        private readonly MessageRecipientResolver $recipientResolver,
        private readonly QuotedPostResolver $quotedPostResolver,
        private readonly QuotedActorResolver $quotedActorResolver,
    ) {}

    public function index(Request $request): View
    {
        $viewer = $request->user()->actor;
        $paginator = $this->conversations->forActor($viewer);

        $previews = [];
        $unreadFlags = [];

        foreach ($paginator as $conversation) {
            $previews[$conversation->id] = $this->conversations->latestMessagePreview($conversation);
            $unreadFlags[$conversation->id] = $this->readTracker->isUnread($conversation, $viewer);
        }

        return view('messages.index', [
            'conversations' => $paginator,
            'previews' => $previews,
            'unreadFlags' => $unreadFlags,
            'viewer' => $viewer,
            'quotedPost' => $this->quotedPostFromRequest($request),
            'quotedActor' => $this->quotedActorFromRequest($request),
        ]);
    }

    public function show(Request $request, Conversation $conversation): View
    {
        $viewer = $request->user()->actor;

        abort_unless($conversation->involves($viewer), 404);

        $conversation->load(['participantLow.user.profile', 'participantHigh.user.profile']);
        $other = $conversation->otherParticipant($viewer);
        $messages = $this->conversations->messagesFor($conversation);

        $this->readTracker->markRead($conversation, $viewer);

        $canSend = $this->policy->canSend($viewer, $other);

        return view('messages.show', [
            'conversation' => $conversation,
            'other' => $other,
            'messages' => $messages,
            'viewer' => $viewer,
            'canSend' => $canSend,
            'quotedPost' => $this->quotedPostFromRequest($request),
            'quotedActor' => $this->quotedActorFromRequest($request),
        ]);
    }

    public function openLocal(Request $request, string $username): RedirectResponse
    {
        $user = User::query()->where('username', $username)->with('actor')->first();

        abort_if($user?->actor === null, 404);

        return $this->redirectToConversation($request, $user->actor);
    }

    public function start(StartMessageRequest $request): RedirectResponse
    {
        $viewer = $request->user()->actor;
        $recipient = $this->recipientResolver->resolve($request->validated('recipient'), $viewer);

        if ($recipient === null) {
            return back()
                ->withErrors(['recipient' => __('openbook.messages.errors.recipient_not_found')])
                ->withInput();
        }

        return $this->redirectToConversation($request, $recipient);
    }

    public function suggestRecipients(Request $request): JsonResponse
    {
        $prefix = (string) $request->query('q', '');
        $viewer = $request->user()->actor;

        $actors = $this->mentionSuggest->forPrefix($prefix, $viewer);

        return response()->json([
            'suggestions' => $actors->map(fn (Actor $actor) => [
                'handle' => $actor->isLocal()
                    ? $actor->preferred_username
                    : $actor->handle(),
                'display_name' => $actor->displayName(),
                'avatar_url' => $actor->avatarUrl(),
                'is_local' => $actor->isLocal(),
                'open_url' => $this->openUrlFor($actor, $this->shareQueryParameters($request)),
            ])->values(),
        ]);
    }

    public function openActor(Request $request, Actor $actor): RedirectResponse
    {
        abort_unless($actor->isPerson() && $actor->isActive(), 404);

        return $this->redirectToConversation($request, $actor);
    }

    private function redirectToConversation(Request $request, Actor $recipient): RedirectResponse
    {
        $viewer = $request->user()->actor;

        if ($recipient->id === $viewer->id) {
            return redirect()->route('messages.index', $this->shareQueryParameters($request));
        }

        $conversation = $this->conversationResolver->findOrCreate($viewer, $recipient);
        $parameters = ['conversation' => $conversation] + $this->shareQueryParameters($request);

        return redirect()->route('messages.show', $parameters);
    }

    /**
     * Parametro ?quote= (post) o ?share= (profilo) da portare nel thread.
     *
     * @return array<string, string>
     */
    private function shareQueryParameters(Request $request): array
    {
        $post = $this->quotedPostFromRequest($request);

        if ($post !== null) {
            return ['quote' => $post->id];
        }

        $actor = $this->quotedActorFromRequest($request);

        if ($actor !== null) {
            return ['share' => $actor->id];
        }

        return [];
    }

    /**
     * Parametro ?quote= (o campo hidden) per citare un post nel prossimo messaggio.
     */
    private function quotedPostFromRequest(Request $request): ?Post
    {
        $id = $request->query('quote') ?? $request->input('quote') ?? $request->input('quoted_post_id');

        return $this->quotedPostResolver->resolveForShare(
            $request->user()?->actor,
            is_string($id) ? $id : null,
        );
    }

    /**
     * Parametro ?share= (o campo hidden) per citare un profilo nel prossimo messaggio.
     */
    private function quotedActorFromRequest(Request $request): ?Actor
    {
        $id = $request->query('share') ?? $request->input('share') ?? $request->input('quoted_actor_id');

        return $this->quotedActorResolver->resolveForShare(
            $request->user()?->actor,
            is_string($id) ? $id : null,
        );
    }

    /**
     * @param  array<string, string>  $shareQuery
     */
    private function openUrlFor(Actor $actor, array $shareQuery): string
    {
        if ($actor->isLocal()) {
            return route('messages.open', ['username' => $actor->preferred_username] + $shareQuery);
        }

        return route('messages.open_actor', ['actor' => $actor] + $shareQuery);
    }

    public function store(StoreMessageRequest $request, Conversation $conversation): RedirectResponse|JsonResponse
    {
        $viewer = $request->user()->actor;

        abort_unless($conversation->involves($viewer), 404);

        $conversation->load(['participantLow', 'participantHigh']);
        $recipient = $conversation->otherParticipant($viewer);

        $quotedId = $request->validated('quoted_post_id') ?? null;
        $quoted = $this->quotedPostResolver->resolveForShare(
            $viewer,
            is_string($quotedId) ? $quotedId : null,
        );
        $quotedActorId = $request->validated('quoted_actor_id') ?? null;
        $quotedActor = $this->quotedActorResolver->resolveForShare(
            $viewer,
            is_string($quotedActorId) ? $quotedActorId : null,
        );

        $post = $this->messageComposer->send(
            $viewer,
            $recipient,
            (string) ($request->validated('body') ?? ''),
            $conversation,
            $quoted,
            $quotedActor,
        );

        $post->load([
            'actor.user.profile',
            'quotedPost.actor.user.profile',
            'quotedPost.community.actor',
            'quotedPost.media.thumbnail',
            'quotedPost.hashtags',
            'quotedActor.user.profile',
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->messagePresenter->toArray($post, $viewer),
            ], 201);
        }

        return redirect()->route('messages.show', $conversation);
    }

    /**
     * Polling leggero del thread: restituisce i messaggi piu' recenti del
     * cursore e un ETag basato sull'ultimo messaggio della conversazione.
     */
    public function feed(Request $request, Conversation $conversation): JsonResponse|Response
    {
        $viewer = $request->user()->actor;

        abort_unless($conversation->involves($viewer), 404);

        $revision = $this->conversations->threadRevision($conversation);
        $etag = '"'.md5($revision).'"';

        if ($this->clientHasCurrentRevision($request, $etag)) {
            return response('', 304)->withHeaders([
                'ETag' => $etag,
                'Cache-Control' => 'private, no-cache',
            ]);
        }

        $afterId = $request->query('after');
        $afterId = is_string($afterId) && $afterId !== '' ? $afterId : null;

        $messages = $this->conversations->messagesAfter($conversation, $afterId);

        if ($afterId === null) {
            $this->readTracker->markRead($conversation, $viewer);
        } elseif ($messages->isNotEmpty()) {
            $this->readTracker->markRead($conversation, $viewer);
        }

        return response()
            ->json([
                'revision' => $revision,
                'messages' => $this->messagePresenter->collection($messages, $viewer),
            ])
            ->withHeaders([
                'ETag' => $etag,
                'Cache-Control' => 'private, no-cache',
            ]);
    }

    private function clientHasCurrentRevision(Request $request, string $etag): bool
    {
        $ifNoneMatch = $request->header('If-None-Match');

        if (is_string($ifNoneMatch) && trim($ifNoneMatch) === $etag) {
            return true;
        }

        $clientRevision = $request->query('revision');

        return is_string($clientRevision) && $clientRevision !== '' && md5($clientRevision) === trim($etag, '"');
    }
}
