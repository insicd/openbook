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
    /**
     * @var list<string>
     */
    private const MESSAGE_RELATIONS = [
        'actor.user.profile',
        'quotedPost.actor.user.profile',
        'quotedPost.community.actor',
        'quotedPost.media.thumbnail',
        'quotedPost.hashtags',
        'quotedActor.user.profile',
    ];

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
            ->with(self::MESSAGE_RELATIONS)
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

    /**
     * Messaggi pubblicati dopo un certo cursore (per polling live del thread).
     *
     * @return Collection<int, Post>
     */
    public function messagesAfter(Conversation $conversation, ?string $afterMessageId = null, int $limit = 50): Collection
    {
        $query = Post::query()
            ->where('conversation_id', $conversation->id)
            ->where('visibility', Post::VISIBILITY_DIRECT)
            ->where('status', Post::STATUS_PUBLISHED)
            ->with(self::MESSAGE_RELATIONS)
            ->orderBy('published_at')
            ->orderBy('id');

        if ($afterMessageId !== null && $afterMessageId !== '') {
            $cursor = Post::query()
                ->where('conversation_id', $conversation->id)
                ->whereKey($afterMessageId)
                ->first(['id', 'published_at']);

            if ($cursor !== null) {
                $query->where(function ($builder) use ($cursor) {
                    $builder->where('published_at', '>', $cursor->published_at)
                        ->orWhere(function ($sameInstant) use ($cursor) {
                            $sameInstant->where('published_at', $cursor->published_at)
                                ->where('id', '>', $cursor->id);
                        });
                });
            }
        }

        return $query->limit($limit)->get();
    }

    public function threadRevision(Conversation $conversation): string
    {
        $latest = $this->latestMessagePreview($conversation);

        if ($latest === null) {
            return 'empty';
        }

        return $latest->id.'@'.$latest->published_at->timestamp;
    }
}
