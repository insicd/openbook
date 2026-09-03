<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follows', function (Blueprint $table) {
            $table->index(['status', 'following_id'], 'follows_popular_remote_actor_index');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->index(['status', 'visibility', 'actor_id', 'published_at'], 'posts_popular_remote_actor_index');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex('posts_popular_remote_actor_index');
        });

        Schema::table('follows', function (Blueprint $table) {
            $table->dropIndex('follows_popular_remote_actor_index');
        });
    }
};
