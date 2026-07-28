<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attivita' grezze ricevute dagli inbox (per-utente e condiviso).
     *
     * In questa fase l'inbox autentica, valida e memorizza soltanto: la
     * trasformazione delle attivita' in effetti di dominio (nuovi follow,
     * like, condivisioni...) arriva con il worker "openbook:process-inbox"
     * della Fase 4. Il vincolo univoco su "remote_activity_uri" e' la difesa
     * principale contro le attivita' duplicate, come richiesto dal design.
     */
    public function up(): void
    {
        Schema::create('inbox_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('target_actor_id')->nullable();
            $table->boolean('is_shared')->default(false);
            $table->string('remote_activity_uri')->unique();
            $table->string('activity_type');
            $table->string('actor_uri');
            $table->longText('payload');
            $table->string('signature_key_id')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->enum('status', ['pending', 'processed', 'failed', 'ignored'])->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->foreign('target_actor_id')->references('id')->on('actors')->nullOnDelete();
            $table->index('status');
            $table->index('activity_type');
            $table->index('actor_uri');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_items');
    }
};
