<?php

namespace App\Application\Queries;

use App\Domain\Messaging\Conversation;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Elenco conversazioni e messaggi per la UI chat.
 */
final class ConversationListQuery
{
    public function forActor(Actor $viewer, int $perPage = 30): LengthAwarePaginator
    {
        return Conversation::query()
            ->where(function ($query) use ($viewer) {
                $query->where('participant_low_id', $viewer->id)
                    ->orWhere('participant_high_id', $viewer->id);
            })
            ->whereNotNull('last_message_at')
            ->with([
                'participantLow.user.profile',
                'participantHigh.user.profile',
            ])
            ->withCount(['messages'])
            ->orderByDesc('last_message_at')
            ->paginate($perPage);
    }

    /**
     * @return Collection<int, Post>
     */
    public function messagesFor(Conversation $conversation, int $limit = 100): Collection
    {
        return Post::query()
            ->where('conversation_id', $conversation->id)
            ->where('visibility', Post::VISIBILITY_DIRECT)
            ->where('status', Post::STATUS_PUBLISHED)
            ->with(['actor.user.profile'])
            ->orderBy('published_at')
            ->limit($limit)
            ->get();
    }

    public function latestMessagePreview(Conversation $conversation): ?Post
    {
        return Post::query()
            ->where('conversation_id', $conversation->id)
            ->where('visibility', Post::VISIBILITY_DIRECT)
            ->where('status', Post::STATUS_PUBLISHED)
            ->with(['actor.user.profile'])
            ->orderByDesc('published_at')
            ->first();
    }
}
