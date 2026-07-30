<?php

namespace App\Http\Controllers\Admin;

use App\Federation\Inbox\InboxItem;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class QueueController extends Controller
{
    public function index(): View
    {
        return view('admin.queue.index', [
            'pendingJobs' => DB::table('jobs')->count(),
            'failedJobs' => DB::table('failed_jobs')->count(),
            'pendingInbox' => InboxItem::query()->where('status', InboxItem::STATUS_PENDING)->count(),
            'failedInbox' => InboxItem::query()->where('status', InboxItem::STATUS_FAILED)->count(),
            'jobs' => DB::table('jobs')->orderByDesc('id')->limit(30)->get(),
            'failed' => DB::table('failed_jobs')->orderByDesc('id')->limit(30)->get(),
            'inboxItems' => InboxItem::query()->orderByDesc('received_at')->limit(30)->get(),
        ]);
    }

    public function retryFailed(string $uuid): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return back()->with('status', __('openbook.admin.queue.retried'));
    }

    public function forgetFailed(string $uuid): RedirectResponse
    {
        Artisan::call('queue:forget', ['id' => $uuid]);

        return back()->with('status', __('openbook.admin.queue.forgotten'));
    }

    public function retryAllFailed(): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => ['all']]);

        return back()->with('status', __('openbook.admin.queue.retried_all'));
    }
}
