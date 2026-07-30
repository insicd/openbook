<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounts\User;
use App\Domain\Moderation\Report;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        return view('admin.dashboard', [
            'openReportsCount' => Report::query()->where('status', Report::STATUS_OPEN)->count(),
            'localUsersCount' => User::query()->count(),
            'suspendedUsersCount' => User::query()->where('status', User::STATUS_SUSPENDED)->count(),
            'moderatorCount' => User::query()->where('is_moderator', true)->where('is_admin', false)->count(),
        ]);
    }
}
