<?php

namespace App\Application\Services;

use App\Domain\Messaging\Conversation;
use App\Domain\Messaging\ConversationRead;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Segna conversazioni come lette e calcola messaggi non letti.
 */
final class ConversationReadTracker
{
    public function markRead(Conversation $conversation, Actor $viewer): void
    {
        $userId = $viewer->user_id;

        if ($userId === null) {
            return;
        }

        DB::table('conversation_reads')->updateOrInsert(
            [
                'conversation_id' => $conversation->id,
                'user_id' => $userId,
            ],
            [
                'last_read_at' => now(),
            ],
        );
    }

    public function unreadCountFor(Actor $viewer): int
    {
        $userId = $viewer->user_id;

        if ($userId === null) {
            return 0;
        }

        return $this->unreadConversations($viewer, $userId)->count();
    }

    public function isUnread(Conversation $conversation, Actor $viewer): bool
    {
        $userId = $viewer->user_id;

        if ($userId === null) {
            return false;
        }

        if ($conversation->last_message_at === null) {
            return false;
        }

        $lastRead = ConversationRead::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->value('last_read_at');

        if ($lastRead === null) {
            return Post::query()
                ->where('conversation_id', $conversation->id)
                ->where('actor_id', '!=', $viewer->id)
                ->exists();
        }

        return $conversation->last_message_at->gt(Carbon::parse($lastRead));
    }

    /**
     * @return Collection<int, Conversation>
     */
    private function unreadConversations(Actor $viewer, string $userId): Collection
    {
        $reads = ConversationRead::query()
            ->where('user_id', $userId)
            ->pluck('last_read_at', 'conversation_id');

        return Conversation::query()
            ->where(function ($query) use ($viewer) {
                $query->where('participant_low_id', $viewer->id)
                    ->orWhere('participant_high_id', $viewer->id);
            })
            ->whereNotNull('last_message_at')
            ->get()
            ->filter(function (Conversation $conversation) use ($viewer, $reads) {
                $lastRead = $reads->get($conversation->id);

                if ($lastRead === null) {
                    return Post::query()
                        ->where('conversation_id', $conversation->id)
                        ->where('actor_id', '!=', $viewer->id)
                        ->exists();
                }

                return $conversation->last_message_at->gt(Carbon::parse($lastRead));
            });
    }
}
