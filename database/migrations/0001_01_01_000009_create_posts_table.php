<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un post locale e' rappresentato principalmente come Note ActivityPub
     * (vedi App\Federation\Serialization, che arrivera' in Fase 3): questa
     * tabella contiene solo il dominio applicativo locale, non la
     * rappresentazione ActivityStreams.
     *
     * "community_id" non e' ancora presente: verra' aggiunta con una
     * migration dedicata nella Fase 5, quando esisteranno le community.
     *
     * I contatori (likes/comments/announces) sono denormalizzati per evitare
     * conteggi pesanti a ogni richiesta del feed, come richiesto dai criteri
     * di prestazione del progetto.
     */
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_id');
            $table->string('title', 255)->nullable();
            $table->string('content_warning', 255)->nullable();
            $table->text('body');
            $table->string('language', 8)->nullable();
            $table->enum('visibility', ['public', 'unlisted', 'followers', 'direct'])->default('public');
            $table->enum('status', ['published', 'deleted'])->default('published');
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('announces_count')->default(0);
            $table->timestamp('published_at');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->foreign('actor_id')->references('id')->on('actors')->cascadeOnDelete();
            $table->index(['actor_id', 'published_at']);
            $table->index(['visibility', 'status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
