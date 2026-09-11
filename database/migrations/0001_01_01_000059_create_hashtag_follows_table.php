<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preferenza locale dell'Actor: non rappresenta un Follow ActivityPub e
     * non viene esposta nelle collection federate followers/following.
     */
    public function up(): void
    {
        Schema::create('hashtag_follows', function (Blueprint $table) {
            $table->uuid('actor_id');
            $table->uuid('hashtag_id');
            $table->timestamps();

            $table->primary(['actor_id', 'hashtag_id']);
            $table->foreign('actor_id')->references('id')->on('actors')->cascadeOnDelete();
            $table->foreign('hashtag_id')->references('id')->on('hashtags')->cascadeOnDelete();
            $table->index('hashtag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hashtag_follows');
    }
};
