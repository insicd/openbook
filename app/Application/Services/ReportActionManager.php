<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Domain\Moderation\Report;
use App\Domain\Moderation\ReportTarget;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReportActionManager
{
    public function __construct(
        private readonly LocalContentDeleter $deleter,
        private readonly ReportManager $reports,
        private readonly AuditLogger $audit,
    ) {}

    /** @param list<string> $targetIds */
    public function action(Report $report, User $reviewer, array $targetIds, bool $deletePost = false, bool $deleteComment = false): void
    {
        abort_unless($reviewer->canModerate(), 403);
        DB::transaction(function () use ($report, $reviewer, $targetIds, $deletePost, $deleteComment): void {
            $report = Report::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();
            // Repeated submissions must not repeat deletions or rewrite the audit trail.
            if (! in_array($report->status, [Report::STATUS_OPEN, Report::STATUS_REVIEWED], true)) {
                return;
            }
            $subjects = [];
            if ($report->isFederated()) {
                $targets = $report->targets()->whereIn('id', $targetIds)->get();
                if ($targets->count() !== count(array_unique($targetIds))) {
                    throw ValidationException::withMessages(['targets' => __('openbook.admin.reports.invalid_targets')]);
                }
                foreach ($targets as $target) {
                    if ($target->type === ReportTarget::TYPE_ACTOR) {
                        throw ValidationException::withMessages(['targets' => __('openbook.admin.reports.invalid_targets')]);
                    }
                    $relation = $target->type === ReportTarget::TYPE_EVENT_COMMENT ? 'eventComment' : $target->type;
                    $subject = $target->$relation()->lockForUpdate()->first();
                    if ($subject === null || $subject->status === 'deleted') {
                        continue;
                    }
                    if (! ReportTarget::isLocalPersonSubject($subject)) {
                        throw ValidationException::withMessages(['targets' => __('openbook.admin.reports.invalid_targets')]);
                    }
                    $subjects[$subject->getMorphClass().':'.$subject->id] = $subject;
                }
            } else {
                if ($deletePost && $report->post !== null) {
                    $subjects[] = $report->post;
                }
                if ($deleteComment && $report->comment !== null) {
                    $subjects[] = $report->comment;
                }
            }
            foreach ($subjects as $subject) {
                if ($this->deleter->delete($reviewer->actor, $subject)) {
                    $this->audit->log($reviewer, 'report.target_deleted', $subject, ['report_id' => $report->id]);
                }
            }
            $this->reports->markActioned($report, $reviewer);
        });
    }
}
