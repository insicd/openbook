<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\User;
use App\Domain\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class NotificationController extends Controller
{
    public function index(): View
    {
        $notifications = Notification::query()
            ->where('recipient_id', auth()->id())
            ->with('actor.user.profile', 'notifiable')
            ->orderByDesc('created_at')
            ->paginate(30);

        $this->markUnreadAsRead(auth()->user());

        return view('notifications.index', [
            'notifications' => $notifications,
        ]);
    }

    public function markAllRead(): RedirectResponse|JsonResponse
    {
        $this->markUnreadAsRead(auth()->user());

        if (request()->expectsJson() || request()->ajax()) {
            return response()->json(['ok' => true]);
        }

        return back();
    }

    /**
     * Anteprima per il polling live. Se il client invia If-None-Match uguale
     * alla revisione corrente, risponde 304 con una sola lettura su "users"
     * (niente count ne' elenco notifiche).
     */
    public function feed(Request $request): JsonResponse|Response
    {
        $recipientId = auth()->id();
        $revision = (int) User::query()->whereKey($recipientId)->value('notifications_revision');
        $etag = '"'.$revision.'"';

        if ($this->clientHasCurrentRevision($request, $revision, $etag)) {
            return response('', 304)->withHeaders([
                'ETag' => $etag,
                'Cache-Control' => 'private, no-cache',
            ]);
        }

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

        return response()
            ->json([
                'revision' => $revision,
                'unread_count' => $unreadCount,
                'notifications' => $notifications,
            ])
            ->withHeaders([
                'ETag' => $etag,
                'Cache-Control' => 'private, no-cache',
            ]);
    }

    private function markUnreadAsRead(?User $user): void
    {
        if ($user === null) {
            return;
        }

        $updated = Notification::query()
            ->where('recipient_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        if ($updated > 0) {
            $user->bumpNotificationsRevision();
        }
    }

    private function clientHasCurrentRevision(Request $request, int $revision, string $etag): bool
    {
        $ifNoneMatch = $request->header('If-None-Match');

        if (is_string($ifNoneMatch) && $ifNoneMatch !== '' && trim($ifNoneMatch) === $etag) {
            return true;
        }

        $clientRevision = $request->query('v');

        return is_string($clientRevision) && $clientRevision !== '' && (int) $clientRevision === $revision;
    }
}
