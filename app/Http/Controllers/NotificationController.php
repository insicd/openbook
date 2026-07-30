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

    /**
     * Anteprima leggera per il polling live della campanella: conteggio
     * non lette + ultime notifiche (stesso perimetro del dropdown header).
     */
    public function feed(): JsonResponse
    {
        $recipientId = auth()->id();

        $unreadCount = Notification::query()
            ->where('recipient_id', $recipientId)
            ->whereNull('read_at')
            ->count();

        $notifications = Notification::query()
            ->where('recipient_id', $recipientId)
            ->with(['actor.user.profile', 'notifiable'])
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(static function (Notification $notification): array {
                $actor = $notification->actor;
                $name = $actor?->displayName() ?: __('openbook.notifications.someone');

                return [
                    'id' => $notification->id,
                    'unread' => ! $notification->isRead(),
                    'message' => __('openbook.notifications.messages.'.$notification->type, ['name' => $name]),
                    'url' => $notification->targetUrl() ?: route('notifications.index'),
                    'time' => $notification->created_at->diffForHumans(),
                    'actor_name' => $name,
                    'actor_avatar' => $actor?->avatarUrl(),
                    'actor_initial' => mb_strtoupper(mb_substr($name, 0, 1)),
                ];
            })
            ->values();

        return response()->json([
            'unread_count' => $unreadCount,
            'notifications' => $notifications,
        ]);
    }
}
