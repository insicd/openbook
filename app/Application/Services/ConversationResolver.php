<?php

namespace App\Application\Services;

use App\Domain\Messaging\Conversation;
use App\Federation\Actors\Actor;
use Illuminate\Support\Carbon;

/**
 * Trova o crea la conversazione canonica tra due Actor (ordine UUID stabile).
 */
final class ConversationResolver
{
    public function findOrCreate(Actor $a, Actor $b): Conversation
    {
        if ($a->id === $b->id) {
            throw new \InvalidArgumentException('Cannot open a conversation with yourself.');
        }

        [$lowId, $highId] = Conversation::orderParticipantIds($a->id, $b->id);

        return Conversation::query()->firstOrCreate(
            [
                'participant_low_id' => $lowId,
                'participant_high_id' => $highId,
            ],
            [
                'last_message_at' => null,
            ],
        );
    }

    public function touch(Conversation $conversation, Carbon $at): void
    {
        Conversation::query()->whereKey($conversation->id)
            ->where(function ($query) use ($at): void {
                $query->whereNull('last_message_at')->orWhere('last_message_at', '<', $at);
            })
            ->update(['last_message_at' => $at]);

        $conversation->refresh();
    }
}
