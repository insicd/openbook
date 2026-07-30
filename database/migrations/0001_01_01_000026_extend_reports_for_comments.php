<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estende le segnalazioni ai commenti: post_id diventa nullable e
     * comment_id opzionale (esattamente uno dei due valorizzato a runtime).
     */
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropForeign(['post_id']);
            $table->dropUnique(['reporter_id', 'post_id']);
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->uuid('post_id')->nullable()->change();
            $table->uuid('comment_id')->nullable()->after('post_id');

            $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
            $table->foreign('comment_id')->references('id')->on('comments')->cascadeOnDelete();
            $table->unique(['reporter_id', 'post_id']);
            $table->unique(['reporter_id', 'comment_id']);
            $table->index('comment_id');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropForeign(['post_id']);
            $table->dropForeign(['comment_id']);
            $table->dropUnique(['reporter_id', 'post_id']);
            $table->dropUnique(['reporter_id', 'comment_id']);
            $table->dropIndex(['comment_id']);
            $table->dropColumn('comment_id');
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->uuid('post_id')->nullable(false)->change();
            $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
            $table->unique(['reporter_id', 'post_id']);
        });
    }
};
