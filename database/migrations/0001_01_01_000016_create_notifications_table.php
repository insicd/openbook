<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le notifiche sono un concetto puramente locale (non federato): il
     * destinatario e' sempre un utente locale. "actor_id" e' l'attore che ha
     * generato la notifica (es. chi ha messo Mi piace), utile anche quando
     * in futuro potra' essere un attore remoto. "notifiable" e' polimorfico
     * per puntare all'oggetto coinvolto (post, commento, ...).
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('recipient_id');
            $table->uuid('actor_id')->nullable();
            $table->string('type', 40);
            $table->uuidMorphs('notifiable');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('recipient_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('actors')->nullOnDelete();
            $table->index(['recipient_id', 'read_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
