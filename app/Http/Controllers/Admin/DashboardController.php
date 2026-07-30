<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounts\User;
use App\Domain\Moderation\Report;
use App\Federation\Inbox\InboxItem;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(): View
    {
        return view('admin.dashboard', [
            'openReportsCount' => Report::query()->where('status', Report::STATUS_OPEN)->count(),
            'localUsersCount' => User::query()->count(),
            'suspendedUsersCount' => User::query()->where('status', User::STATUS_SUSPENDED)->count(),
            'moderatorCount' => User::query()->where('is_moderator', true)->where('is_admin', false)->count(),
            'failedJobsCount' => DB::table('failed_jobs')->count(),
            'pendingInboxCount' => InboxItem::query()->where('status', InboxItem::STATUS_PENDING)->count(),
        ]);
    }
}
