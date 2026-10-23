<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Domain\Comments\Comment;
use App\Domain\Events\Event;
use App\Domain\Events\EventComment;
use App\Domain\Moderation\Report;
use App\Domain\Moderation\ReportTarget;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Support\ActivityPubUri;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Persiste i casi locali e federati e le transizioni della moderazione.
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

    /**
     * Receives targets already resolved by the inbox processor. A null subject
     * represents a recognised local URI whose content is no longer available.
     * Authentication and URI resolution remain in the ordinary inbox flow.
     * Returns null when the receipt belongs to a case that has been purged.
     *
     * @param  list<array{uri: string, type: string, subject: Actor|Post|Comment|Event|EventComment|null}>  $targets
     */
    public function recordFederated(Actor $sender, string $activityUri, ?string $details, array $targets): ?Report
    {
        $hash = hash('sha256', $activityUri);
        $receipt = DB::table('report_receipts')->where('activity_hash', $hash)->first();
        if ($receipt !== null) {
            return $receipt->report_id === null ? null : Report::query()->find($receipt->report_id);
        }

        if ($sender->isLocal() || ! $sender->exists || $activityUri === '' || $targets === []) {
            throw new InvalidArgumentException('Segnalazione federata non valida.');
        }

        $rows = [];
        foreach ($targets as $target) {
            $row = $this->federatedTargetAttributes($target);
            $rows[$row['uri_hash']] = $row;
        }
        $content = array_filter($rows, fn (array $row): bool => $row['type'] !== ReportTarget::TYPE_ACTOR);
        $unavailable = ! in_array(false, array_column($content ?: $rows, 'unavailable'), true);

        try {
            return DB::transaction(function () use ($sender, $activityUri, $details, $rows, $unavailable, $hash): Report {
                DB::table('report_receipts')->insert(['activity_hash' => $hash]);
                $report = Report::query()->create([
                    'origin' => Report::ORIGIN_FEDERATED,
                    'reporter_actor_id' => $sender->id,
                    'reporter_uri' => $sender->uri,
                    'reporter_domain' => parse_url($sender->uri, PHP_URL_HOST),
                    'remote_activity_uri' => $activityUri,
                    'reason' => Report::REASON_OTHER,
                    'details' => filled($details) ? trim($details) : null,
                    'status' => $unavailable ? Report::STATUS_ACTIONED : Report::STATUS_OPEN,
                    'closed_at' => $unavailable ? now() : null,
                    'closure_reason' => $unavailable ? Report::CLOSURE_UNAVAILABLE : null,
                ]);
                $report->targets()->createMany(array_values($rows));
                DB::table('report_receipts')->where('activity_hash', $hash)->update(['report_id' => $report->id]);

                return $report;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Read the winner even inside an outer MySQL repeatable-read snapshot.
            $receipt = DB::table('report_receipts')->where('activity_hash', $hash)->lockForUpdate()->first();
            if ($receipt === null) {
                throw $exception;
            }

            return $receipt->report_id === null ? null : Report::query()->lockForUpdate()->find($receipt->report_id);
        }
    }

    /**
     * @param  array{uri: string, type: string, subject: Actor|Post|Comment|Event|EventComment|null}  $target
     * @return array<string, mixed>
     */
    private function federatedTargetAttributes(array $target): array
    {
        [$class, $column] = match ($target['type']) {
            ReportTarget::TYPE_ACTOR => [Actor::class, 'actor_id'],
            ReportTarget::TYPE_POST => [Post::class, 'post_id'],
            ReportTarget::TYPE_COMMENT => [Comment::class, 'comment_id'],
            ReportTarget::TYPE_EVENT => [Event::class, 'event_id'],
            ReportTarget::TYPE_EVENT_COMMENT => [EventComment::class, 'event_comment_id'],
            default => throw new InvalidArgumentException('Tipo di soggetto non valido.'),
        };
        $subject = $target['subject'];
        if ($target['uri'] === '' || ($subject !== null && (! $subject instanceof $class || ! $subject->exists))) {
            throw new InvalidArgumentException('Riferimento di segnalazione non valido.');
        }
        if ($subject !== null && ! ReportTarget::isLocalPersonSubject($subject)) {
            throw new InvalidArgumentException('Il soggetto deve appartenere a una persona locale.');
        }

        return [
            'type' => $target['type'],
            'uri' => $target['uri'],
            'uri_hash' => hash('sha256', ActivityPubUri::normalize($target['uri'])),
            'unavailable' => $subject === null || $subject->status === 'deleted',
            $column => $subject?->getKey(),
        ];
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
            'closed_at' => in_array($status, [Report::STATUS_DISMISSED, Report::STATUS_ACTIONED], true) ? now() : null,
            'closure_reason' => null,
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
