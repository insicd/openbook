<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "name" e' sempre normalizzato in minuscolo, senza il simbolo "#".
     */
    public function up(): void
    {
        Schema::create('hashtags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });

        Schema::create('post_hashtags', function (Blueprint $table) {
            $table->uuid('post_id');
            $table->uuid('hashtag_id');

            $table->primary(['post_id', 'hashtag_id']);
            $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
            $table->foreign('hashtag_id')->references('id')->on('hashtags')->cascadeOnDelete();
            $table->index('hashtag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_hashtags');
        Schema::dropIfExists('hashtags');
    }
};
