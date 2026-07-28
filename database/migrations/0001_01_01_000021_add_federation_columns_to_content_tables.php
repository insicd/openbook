<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La Fase 4 introduce contenuti *remoti* in cache locale (post e commenti
     * creati da un Actor remoto e ricevuti tramite l'inbox): serve un modo
     * per riconoscere l'identificatore ActivityPub originale, cosi' da poter
     * deduplicare le "Create" e risolvere "Update"/"Delete"/"Like"/"Announce"
     * successive che vi fanno riferimento. Per le righe locali questa colonna
     * resta sempre NULL: il loro identificatore canonico continua a essere
     * derivato da "/posts/{id}" e "/comments/{id}" (vedi NoteSerializer),
     * senza bisogno di duplicarlo qui.
     *
     * "follows.remote_activity_uri" memorizza invece l'id dell'attivita'
     * "Follow" originale quando la riga nasce da una richiesta remota in
     * ingresso: serve a costruire l'"object" dell'Accept/Reject di risposta.
     * Resta NULL per i follow locale-locale e per quelli che partono da
     * questa istanza verso un attore remoto (in quel caso e' la controparte
     * remota a doverci rispondere, non il contrario).
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('uri')->nullable()->unique()->after('actor_id');
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->string('uri')->nullable()->unique()->after('actor_id');
        });

        Schema::table('follows', function (Blueprint $table) {
            $table->string('remote_activity_uri')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('uri');
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn('uri');
        });

        Schema::table('follows', function (Blueprint $table) {
            $table->dropColumn('remote_activity_uri');
        });
    }
};
