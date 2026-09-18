<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_publication_queue', function (Blueprint $table) {
            $table->index(['status', 'updated_at'], 'post_publication_queue_cleanup_index');
        });
    }

    public function down(): void
    {
        Schema::table('post_publication_queue', function (Blueprint $table) {
            $table->dropIndex('post_publication_queue_cleanup_index');
        });
    }
};
