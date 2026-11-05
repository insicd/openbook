<?php

namespace App\Http\Controllers\Admin;

use App\Federation\Inbox\InboxItem;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class QueueController extends Controller
{
    public function index(Request $request): View
    {
        $activeTab = in_array($request->query('tab'), ['failed', 'inbox'], true) ? $request->query('tab') : 'pending';

        return view('admin.queue.index', [
            'activeTab' => $activeTab,
            'tabs' => array_map(fn ($tab) => ['label' => __('openbook.admin.queue.tab_'.$tab), 'url' => route('admin.queue.index', ['tab' => $tab]), 'active' => $tab === $activeTab], ['pending', 'failed', 'inbox']),
            'pendingJobs' => DB::table('jobs')->count(),
            'failedJobs' => DB::table('failed_jobs')->count(),
            'pendingInbox' => InboxItem::query()->where('status', InboxItem::STATUS_PENDING)->count(),
            'failedInbox' => InboxItem::query()->where('status', InboxItem::STATUS_FAILED)->count(),
            'jobs' => $activeTab === 'pending' ? DB::table('jobs')->orderByDesc('id')->limit(30)->get() : collect(),
            'failed' => $activeTab === 'failed' ? DB::table('failed_jobs')->orderByDesc('id')->limit(30)->get() : collect(),
            'inboxItems' => $activeTab === 'inbox' ? InboxItem::query()->orderByDesc('received_at')->limit(30)->get() : collect(),
        ]);
    }

    public function retryFailed(string $uuid): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return redirect()->route('admin.queue.index', ['tab' => 'failed'])->with('status', __('openbook.admin.queue.retried'));
    }

    public function forgetFailed(string $uuid): RedirectResponse
    {
        Artisan::call('queue:forget', ['id' => $uuid]);

        return redirect()->route('admin.queue.index', ['tab' => 'failed'])->with('status', __('openbook.admin.queue.forgotten'));
    }

    public function retryAllFailed(): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => ['all']]);

        return redirect()->route('admin.queue.index', ['tab' => 'failed'])->with('status', __('openbook.admin.queue.retried_all'));
    }
}
