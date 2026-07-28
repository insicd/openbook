<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Coppia di chiavi crittografiche per la firma HTTP delle attivita'.
     * La chiave privata e' presente soltanto per gli Actor locali e viene
     * sempre salvata cifrata (cast Eloquent "encrypted"): non deve mai
     * essere memorizzata in chiaro ne' restituita da API o log.
     */
    public function up(): void
    {
        Schema::create('actor_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_id')->unique();
            $table->text('public_key');
            $table->text('private_key')->nullable();
            $table->timestamps();

            $table->foreign('actor_id')->references('id')->on('actors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actor_keys');
    }
};
