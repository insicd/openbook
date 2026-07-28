<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Modella la relazione sociale a livello di Actor (non di User), perche'
     * sul piano ActivityPub il follow avviene sempre tra Actor: questo rende
     * la tabella riusabile senza modifiche quando arrivera' il follow
     * federato (Fase 4), che user' semplicemente "follower_id"/"following_id"
     * puntando anche ad attori remoti.
     *
     * "status" gestisce l'approvazione manuale per gli account protetti
     * (manually_approves_followers su "actors"): l'equivalente locale di
     * Follow/Accept/Reject ActivityPub.
     */
    public function up(): void
    {
        Schema::create('follows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('follower_id');
            $table->uuid('following_id');
            $table->enum('status', ['pending', 'accepted'])->default('accepted');
            $table->timestamp('requested_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->foreign('follower_id')->references('id')->on('actors')->cascadeOnDelete();
            $table->foreign('following_id')->references('id')->on('actors')->cascadeOnDelete();
            $table->unique(['follower_id', 'following_id']);
            $table->index(['following_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follows');
    }
};
