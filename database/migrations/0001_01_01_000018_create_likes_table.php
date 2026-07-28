<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Polimorfica ("likeable") per coprire sia i post sia i commenti. Il
     * vincolo di unicita' impedisce Mi piace duplicati dallo stesso attore
     * sullo stesso contenuto, richiesto esplicitamente dal design.
     */
    public function up(): void
    {
        Schema::create('likes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_id');
            $table->uuidMorphs('likeable');
            $table->timestamps();

            $table->foreign('actor_id')->references('id')->on('actors')->cascadeOnDelete();
            $table->unique(['actor_id', 'likeable_type', 'likeable_id'], 'likes_actor_target_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('likes');
    }
};
