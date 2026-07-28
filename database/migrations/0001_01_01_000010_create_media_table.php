<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "path" e' sempre un nome generato casualmente (mai il nome originale
     * del file caricato, per evitare path traversal e per non rivelare
     * informazioni sul filesystem dell'utente). "original_name" e' tenuto
     * solo come riferimento informativo, non viene mai usato per comporre
     * un URL o un percorso su disco.
     */
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_id');
            $table->string('disk', 32)->default('public');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('byte_size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt_text', 1000)->nullable();
            $table->timestamps();

            $table->foreign('actor_id')->references('id')->on('actors')->cascadeOnDelete();
            $table->index('actor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
