<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\User;
use App\Application\Queries\ConversationListQuery;
use App\Application\Services\ConversationReadTracker;
use App\Application\Services\ConversationResolver;
use App\Application\Services\DirectMessagePolicy;
use App\Application\Services\MessageComposer;
use App\Domain\Messaging\Conversation;
use App\Federation\Actors\Actor;
use App\Http\Requests\Messages\StoreMessageRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function __construct(
        private readonly ConversationListQuery $conversations,
        private readonly ConversationReadTracker $readTracker,
        private readonly ConversationResolver $conversationResolver,
        private readonly MessageComposer $messageComposer,
        private readonly DirectMessagePolicy $policy,
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
        ]);
    }

    public function openLocal(Request $request, string $username): RedirectResponse
    {
        $user = User::query()->where('username', $username)->with('actor')->first();

        abort_if($user?->actor === null, 404);

        return $this->redirectToConversation($request, $user->actor);
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
            return redirect()->route('messages.index');
        }

        $conversation = $this->conversationResolver->findOrCreate($viewer, $recipient);

        return redirect()->route('messages.show', $conversation);
    }

    public function store(StoreMessageRequest $request, Conversation $conversation): RedirectResponse
    {
        $viewer = $request->user()->actor;

        abort_unless($conversation->involves($viewer), 404);

        $conversation->load(['participantLow', 'participantHigh']);
        $recipient = $conversation->otherParticipant($viewer);

        $this->messageComposer->send(
            $viewer,
            $recipient,
            $request->validated('body'),
            $conversation,
        );

        return redirect()->route('messages.show', $conversation);
    }
}
