<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Citazione (quote post): un nuovo post locale puo' riferire un post
     * esistente (locale o remoto in cache) mostrato annidato sotto il testo.
     * Diverso da "announces" (condivisione diretta / Announce ActivityPub),
     * che non crea una nuova riga in "posts".
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->uuid('quoted_post_id')->nullable()->after('uri');
            $table->foreign('quoted_post_id')->references('id')->on('posts')->nullOnDelete();
            $table->index('quoted_post_id');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropForeign(['quoted_post_id']);
            $table->dropIndex(['quoted_post_id']);
            $table->dropColumn('quoted_post_id');
        });
    }
};
