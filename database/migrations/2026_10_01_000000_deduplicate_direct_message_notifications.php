<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->select('id')->chunkById(100, function ($users): void {
            foreach ($users as $user) {
                DB::transaction(function () use ($user): void {
                    $groups = DB::table('notifications')
                        ->join('posts', 'posts.id', '=', 'notifications.notifiable_id')
                        ->where('notifications.recipient_id', $user->id)
                        ->where('notifications.type', 'direct_message')
                        ->where('notifications.notifiable_type', 'post')
                        ->whereNotNull('posts.conversation_id')
                        ->orderByDesc('notifications.created_at')
                        ->orderByDesc('notifications.id')
                        ->get(['notifications.id', 'notifications.read_at', 'posts.conversation_id'])
                        ->groupBy('conversation_id');

                    $changed = false;

                    foreach ($groups as $notifications) {
                        if ($notifications->count() < 2) {
                            continue;
                        }

                        $latest = $notifications->first();

                        if ($latest->read_at !== null && $notifications->contains(fn ($notification): bool => $notification->read_at === null)) {
                            DB::table('notifications')->where('id', $latest->id)->update(['read_at' => null]);
                        }

                        foreach ($notifications->skip(1)->pluck('id')->chunk(500) as $ids) {
                            DB::table('notifications')->whereIn('id', $ids->all())->delete();
                        }

                        $changed = true;
                    }

                    if ($changed) {
                        DB::table('users')->where('id', $user->id)->increment('notifications_revision');
                    }
                });
            }
        });
    }

    public function down(): void
    {
        // La pulizia dello storico non e' reversibile.
    }
};
