<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_reads', function (Blueprint $table): void {
            // Il cursore deve sopravvivere anche all'eventuale rimozione del post.
            $table->uuid('last_read_message_id')->nullable();
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->index(['conversation_id', 'visibility', 'status', 'created_at', 'id'], 'posts_conversation_arrival_index');
        });

        // Gli indici composti coprono anche le foreign key sulle rispettive prime colonne.
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropIndex('posts_conversation_id_index');
            $table->dropIndex('posts_community_id_index');
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->index(['recipient_id', 'type', 'created_at', 'id'], 'notifications_recipient_type_created_index');
        });

        DB::table('conversations')->select('id')->chunkById(100, function ($conversations): void {
            foreach ($conversations as $conversation) {
                DB::transaction(function () use ($conversation): void {
                    $messages = DB::table('posts')->where('conversation_id', $conversation->id)
                        ->where('visibility', 'direct')->where('status', 'published');

                    DB::table('conversations')->where('id', $conversation->id)
                        ->update(['last_message_at' => (clone $messages)->max('created_at')]);

                    foreach (DB::table('conversation_reads')->where('conversation_id', $conversation->id)->get() as $read) {
                        $last = $read->last_read_at === null ? null : (clone $messages)
                            ->where('created_at', '<=', $read->last_read_at)
                            ->orderByDesc('created_at')->orderByDesc('id')->first(['id', 'created_at']);

                        DB::table('conversation_reads')->where('conversation_id', $conversation->id)->where('user_id', $read->user_id)
                            ->update(['last_read_at' => $last?->created_at, 'last_read_message_id' => $last?->id]);
                    }
                });
            }
        });

        // I client già aperti devono ricaricare i badge dopo la conversione delle letture.
        DB::table('users')->increment('notifications_revision');
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex('notifications_recipient_type_created_index');
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->index('conversation_id', 'posts_conversation_id_index');
            $table->index('community_id', 'posts_community_id_index');
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->dropIndex('posts_conversation_arrival_index');
        });

        Schema::table('conversation_reads', function (Blueprint $table): void {
            $table->dropColumn('last_read_message_id');
        });
    }
};
