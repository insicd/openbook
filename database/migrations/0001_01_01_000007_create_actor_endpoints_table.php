<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Endpoint ActivityPub dichiarati da un Actor (inbox, outbox, followers,
     * following, shared inbox). Separata da "actors" per rispecchiare
     * fedelmente la struttura del documento Actor e semplificarne
     * l'aggiornamento indipendente in fase di refresh cache remoto.
     */
    public function up(): void
    {
        Schema::create('actor_endpoints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_id')->unique();
            $table->string('inbox')->nullable();
            $table->string('outbox')->nullable();
            $table->string('followers')->nullable();
            $table->string('following')->nullable();
            $table->string('shared_inbox')->nullable();
            $table->timestamps();

            $table->foreign('actor_id')->references('id')->on('actors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actor_endpoints');
    }
};
