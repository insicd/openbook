<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->index(
                ['status', 'conversation_id', 'published_at', 'id', 'actor_id'],
                'posts_personal_feed_timeline_index',
            );
            $table->index(
                ['community_id', 'status', 'conversation_id', 'published_at', 'id', 'actor_id'],
                'posts_community_feed_timeline_index',
            );
        });

        Schema::table('announces', function (Blueprint $table) {
            $table->index(
                ['created_at', 'post_id', 'id', 'actor_id'],
                'announces_personal_feed_timeline_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('announces', function (Blueprint $table) {
            $table->dropIndex('announces_personal_feed_timeline_index');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex('posts_personal_feed_timeline_index');
            $table->dropIndex('posts_community_feed_timeline_index');
        });
    }
};
