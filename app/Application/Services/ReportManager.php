<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Domain\Comments\Comment;
use App\Domain\Moderation\Report;
use App\Domain\Posts\Post;
use InvalidArgumentException;

/**
 * Archivia e gestisce segnalazioni locali su post e commenti. Idempotente
 * per utente+target in ingresso; le azioni di review aggiornano status e
 * revisore per il pannello di moderazione.
 */
final class ReportManager
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

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

        $reason = $this->validatedReason($data['reason'] ?? null);

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
            'comment_id' => null,
            'reason' => $reason,
            'details' => filled($data['details'] ?? null) ? trim((string) $data['details']) : null,
            'status' => Report::STATUS_OPEN,
        ]);
    }

    /**
     * @param  array{reason: string, details?: ?string}  $data
     */
    public function reportComment(User $reporter, Comment $comment, array $data): Report
    {
        if ($comment->actor?->user_id === $reporter->id) {
            throw new InvalidArgumentException('Non puoi segnalare un tuo commento.');
        }

        if ($comment->status === Comment::STATUS_DELETED) {
            throw new InvalidArgumentException('Questo commento non puo\' essere segnalato.');
        }

        $reason = $this->validatedReason($data['reason'] ?? null);

        $existing = Report::query()
            ->where('reporter_id', $reporter->id)
            ->where('comment_id', $comment->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Report::query()->create([
            'reporter_id' => $reporter->id,
            'post_id' => null,
            'comment_id' => $comment->id,
            'reason' => $reason,
            'details' => filled($data['details'] ?? null) ? trim((string) $data['details']) : null,
            'status' => Report::STATUS_OPEN,
        ]);
    }

    public function markReviewed(Report $report, User $reviewer): Report
    {
        return $this->updateStatus($report, $reviewer, Report::STATUS_REVIEWED);
    }

    public function dismiss(Report $report, User $reviewer): Report
    {
        return $this->updateStatus($report, $reviewer, Report::STATUS_DISMISSED);
    }

    public function markActioned(Report $report, User $reviewer): Report
    {
        return $this->updateStatus($report, $reviewer, Report::STATUS_ACTIONED);
    }

    private function updateStatus(Report $report, User $reviewer, string $status): Report
    {
        if (! $reviewer->canModerate()) {
            throw new InvalidArgumentException('Non hai i permessi di moderazione.');
        }

        $report->forceFill([
            'status' => $status,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ])->save();

        $this->auditLogger->log($reviewer, 'report.'.$status, $report);

        return $report->refresh();
    }

    private function validatedReason(mixed $reason): string
    {
        if (! is_string($reason) || ! in_array($reason, Report::reasons(), true)) {
            throw new InvalidArgumentException('Motivo di segnalazione non valido.');
        }

        return $reason;
    }
}
