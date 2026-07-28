<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Una condivisione (Announce) non duplica mai il post originale: e' solo
     * un riferimento "actor ha condiviso post" con la propria data. Il
     * vincolo di unicita' impedisce condivisioni duplicate dello stesso
     * attore sullo stesso post.
     */
    public function up(): void
    {
        Schema::create('announces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_id');
            $table->uuid('post_id');
            $table->timestamps();

            $table->foreign('actor_id')->references('id')->on('actors')->cascadeOnDelete();
            $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
            $table->unique(['actor_id', 'post_id']);
            $table->index(['post_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announces');
    }
};
