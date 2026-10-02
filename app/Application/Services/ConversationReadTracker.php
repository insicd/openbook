<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Domain\Messaging\Conversation;
use App\Domain\Messaging\ConversationRead;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Segna conversazioni come lette e conta le conversazioni con messaggi non letti.
 */
final class ConversationReadTracker
{
    public function markRead(Conversation $conversation, Actor $viewer, ?Post $lastMessage = null): void
    {
        $userId = $viewer->user_id;

        if ($userId === null) {
            return;
        }

        abort_unless($conversation->involves($viewer), 403);
        abort_unless($lastMessage === null || $lastMessage->conversation_id === $conversation->id, 403);

        DB::transaction(function () use ($conversation, $userId, $lastMessage): void {
            $read = ConversationRead::query()
                ->where('conversation_id', $conversation->id)->where('user_id', $userId)->first();

            if ($read !== null && $lastMessage === null) {
                return;
            }

            if ($read?->last_read_message_id !== null
                && ($read->last_read_at->timestamp > $lastMessage->created_at->timestamp
                    || ($read->last_read_at->timestamp === $lastMessage->created_at->timestamp
                        && strcmp($read->last_read_message_id, $lastMessage->id) >= 0))) {
                return;
            }

            DB::table('conversation_reads')->updateOrInsert(
                ['conversation_id' => $conversation->id, 'user_id' => $userId],
                ['last_read_at' => $lastMessage?->created_at, 'last_read_message_id' => $lastMessage?->id],
            );

            if ($lastMessage === null) {
                return;
            }

            $shownMessageIds = Post::query()->select('id')
                ->where('conversation_id', $conversation->id)
                ->where(function (Builder $query) use ($lastMessage): void {
                    $query->where('created_at', '<', $lastMessage->created_at)
                        ->orWhere(function (Builder $query) use ($lastMessage): void {
                            $query->where('created_at', $lastMessage->created_at)->where('id', '<=', $lastMessage->id);
                        });
                });

            Notification::query()->forDirectConversation($userId, $conversation->id)
                ->whereNull('read_at')->whereIn('notifiable_id', $shownMessageIds)
                ->update(['read_at' => now()]);

            // Il badge chat cambia anche quando la notifica generale era già letta.
            User::query()->whereKey($userId)->increment('notifications_revision');
        });
    }

    public function unreadCountFor(Actor $viewer): int
    {
        return $viewer->user_id === null ? 0 : $this->unreadQuery($viewer)->count();
    }

    public function invalidateOnPostChange(Post $post): void
    {
        if ($post->conversation_id === null || ! $post->wasChanged(['status', 'visibility'])) {
            return;
        }

        $conversation = $post->conversation;
        if ($conversation === null) {
            return;
        }

        $userIds = Actor::query()
            ->whereKey([$conversation->participant_low_id, $conversation->participant_high_id])
            ->whereNotNull('user_id')->pluck('user_id');

        User::query()->whereKey($userIds)->increment('notifications_revision');
    }

    public function isUnread(Conversation $conversation, Actor $viewer): bool
    {
        return $viewer->user_id !== null && $this->unreadQuery($viewer)->whereKey($conversation->id)->exists();
    }

    private function unreadQuery(Actor $viewer): Builder
    {
        return Conversation::query()
            ->leftJoin('conversation_reads as reads', function ($join) use ($viewer): void {
                $join->on('reads.conversation_id', '=', 'conversations.id')->where('reads.user_id', $viewer->user_id);
            })
            ->where(function (Builder $query) use ($viewer): void {
                $query->where('participant_low_id', $viewer->id)->orWhere('participant_high_id', $viewer->id);
            })
            ->whereExists(function ($query) use ($viewer): void {
                $query->selectRaw('1')->from('posts')
                    ->whereColumn('posts.conversation_id', 'conversations.id')
                    ->where('posts.visibility', Post::VISIBILITY_DIRECT)->where('posts.status', Post::STATUS_PUBLISHED)
                    ->where('posts.actor_id', '!=', $viewer->id)
                    ->where(function ($query): void {
                        $query->whereNull('reads.last_read_message_id')
                            ->orWhereColumn('posts.created_at', '>', 'reads.last_read_at')
                            ->orWhere(function ($query): void {
                                $query->whereColumn('posts.created_at', 'reads.last_read_at')
                                    ->whereColumn('posts.id', '>', 'reads.last_read_message_id');
                            });
                    });
            });
    }
}
