<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Polimorfica fin da subito ("mentionable"): in questo milestone si
     * applica solo ai post, ma verra' riusata per i commenti nel prossimo
     * passaggio senza bisogno di una nuova migration.
     */
    public function up(): void
    {
        Schema::create('mentions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('mentionable');
            $table->uuid('actor_id');
            $table->timestamps();

            $table->foreign('actor_id')->references('id')->on('actors')->cascadeOnDelete();
            $table->unique(['mentionable_type', 'mentionable_id', 'actor_id'], 'mentions_target_actor_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mentions');
    }
};
