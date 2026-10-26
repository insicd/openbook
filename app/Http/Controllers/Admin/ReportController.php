<?php

namespace App\Http\Controllers\Admin;

use App\Application\Queries\ReportModerationQuery;
use App\Application\Services\ReportActionManager;
use App\Application\Services\ReportManager;
use App\Domain\Moderation\Report;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportManager $reportManager,
        private readonly ReportActionManager $actions,
        private readonly ReportModerationQuery $reports,
    ) {}

    public function index(Request $request): View
    {
        $status = (string) $request->query('status', Report::STATUS_OPEN);
        $allowed = [
            Report::STATUS_OPEN,
            Report::STATUS_REVIEWED,
            Report::STATUS_DISMISSED,
            Report::STATUS_ACTIONED,
            'all',
        ];

        if (! in_array($status, $allowed, true)) {
            $status = Report::STATUS_OPEN;
        }

        return view('admin.reports.index', [
            'reports' => $this->reports->listing($status)->paginate(20)->withQueryString(),
            'status' => $status,
        ]);
    }

    public function show(Report $report): View
    {
        $report->load(['reporter.profile', 'reviewer']);

        return view('admin.reports.show', [
            'report' => $report,
            'previews' => $this->reports->previews($report, auth()->user()->actor),
        ]);
    }

    public function review(Report $report): RedirectResponse
    {
        $this->reportManager->markReviewed($report, auth()->user());

        return back()->with('status', __('openbook.admin.reports.marked_reviewed'));
    }

    public function dismiss(Report $report): RedirectResponse
    {
        $this->reportManager->dismiss($report, auth()->user());

        return back()->with('status', __('openbook.admin.reports.marked_dismissed'));
    }

    public function action(Request $request, Report $report): RedirectResponse
    {
        $data = $request->validate([
            'targets' => ['sometimes', 'array'],
            'targets.*' => ['required', 'uuid', 'distinct'],
            'delete_post' => ['sometimes', 'boolean'],
            'delete_comment' => ['sometimes', 'boolean'],
        ]);
        $this->actions->action(
            $report, $request->user(), $data['targets'] ?? [],
            $request->boolean('delete_post'), $request->boolean('delete_comment'),
        );

        return back()->with('status', __('openbook.admin.reports.marked_actioned'));
    }
}
