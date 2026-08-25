<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Citazione di un profilo in un messaggio diretto: punta all'Actor in
     * cache locale (Person locale o remoto), cosi' il destinatario apre la
     * pagina Openbook e puo' seguire senza passare dall'URI ActivityPub.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->uuid('quoted_actor_id')->nullable()->after('quoted_post_id');
            $table->foreign('quoted_actor_id')->references('id')->on('actors')->nullOnDelete();
            $table->index('quoted_actor_id');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropForeign(['quoted_actor_id']);
            $table->dropIndex(['quoted_actor_id']);
            $table->dropColumn('quoted_actor_id');
        });
    }
};
