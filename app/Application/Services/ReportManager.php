<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Domain\Moderation\Report;
use App\Domain\Posts\Post;
use InvalidArgumentException;

/**
 * Archivia segnalazioni locali su post. Idempotente per utente+post: una
 * seconda segnalazione sullo stesso contenuto non crea una riga nuova.
 */
final class ReportManager
{
    /**
     * @param  array{reason: string, details?: ?string}  $data
     */
    public function reportPost(User $reporter, Post $post, array $data): Report
    {
        if ($post->actor?->user_id === $reporter->id) {
            throw new InvalidArgumentException('Non puoi segnalare un tuo post.');
        }

        if ($post->status === Post::STATUS_DELETED) {
            throw new InvalidArgumentException('Questo post non puo\' essere segnalato.');
        }

        $reason = $data['reason'];

        if (! in_array($reason, Report::reasons(), true)) {
            throw new InvalidArgumentException('Motivo di segnalazione non valido.');
        }

        $existing = Report::query()
            ->where('reporter_id', $reporter->id)
            ->where('post_id', $post->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Report::query()->create([
            'reporter_id' => $reporter->id,
            'post_id' => $post->id,
            'reason' => $reason,
            'details' => filled($data['details'] ?? null) ? trim((string) $data['details']) : null,
            'status' => Report::STATUS_OPEN,
        ]);
    }
}
