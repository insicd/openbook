<?php

use App\Federation\Outbox\RemoteOutboxFetcher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vedi {@see RemoteOutboxFetcher}: quando si
     * visita per la prima volta (o dopo la scadenza della cache) il profilo
     * di un Actor remoto, l'outbox reale del suo server di origine viene
     * interrogato per recuperarne i post pubblici piu' recenti. Questa
     * colonna registra l'ultimo tentativo (riuscito o meno, per evitare di
     * martellare un server remoto irraggiungibile a ogni caricamento di
     * pagina), sullo stesso modello di "last_fetched_at" per il documento
     * Actor.
     */
    public function up(): void
    {
        Schema::table('actors', function (Blueprint $table) {
            $table->timestamp('posts_fetched_at')->nullable()->after('last_fetched_at');
        });
    }

    public function down(): void
    {
        Schema::table('actors', function (Blueprint $table) {
            $table->dropColumn('posts_fetched_at');
        });
    }
};
