<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * I commenti sono, concettualmente, normali Note ActivityPub con
     * "inReplyTo" (vedi il design generale di Openbook): sono pero' tenuti in
     * una tabella separata da "posts" per semplificare le query del feed
     * (che non deve mai includere i commenti) e perche' non condividono tutti
     * i campi di un post (titolo, content warning, visibilita' propria: un
     * commento eredita sempre la visibilita' del post a cui appartiene).
     *
     * "post_id" punta sempre al post radice della discussione (anche per le
     * risposte annidate), per poter caricare un intero thread con una sola
     * query; "parent_comment_id" gestisce l'annidamento vero e proprio.
     */
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('post_id');
            $table->uuid('parent_comment_id')->nullable();
            $table->uuid('actor_id');
            $table->text('body');
            $table->enum('status', ['published', 'deleted'])->default('published');
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('replies_count')->default(0);
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
            $table->foreign('parent_comment_id')->references('id')->on('comments')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('actors')->cascadeOnDelete();
            $table->index(['post_id', 'created_at']);
            $table->index('parent_comment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
