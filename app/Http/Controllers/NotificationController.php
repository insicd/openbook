<?php

namespace App\Http\Controllers;

use App\Domain\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class NotificationController extends Controller
{
    public function index(): View
    {
        $notifications = Notification::query()
            ->where('recipient_id', auth()->id())
            ->with('actor.user.profile', 'notifiable')
            ->orderByDesc('created_at')
            ->paginate(30);

        Notification::query()
            ->where('recipient_id', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return view('notifications.index', [
            'notifications' => $notifications,
        ]);
    }

    public function markAllRead(): RedirectResponse|JsonResponse
    {
        Notification::query()
            ->where('recipient_id', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        if (request()->expectsJson() || request()->ajax()) {
            return response()->json(['ok' => true]);
        }

        return back();
    }
}
