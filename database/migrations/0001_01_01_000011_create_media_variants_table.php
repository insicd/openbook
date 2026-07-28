<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Varianti derivate di un media originale (per ora solo "thumbnail",
     * generata in modo sincrono con GD al momento dell'upload). La struttura
     * a tabella separata permette di aggiungere in futuro altre dimensioni
     * senza modificare lo schema.
     */
    public function up(): void
    {
        Schema::create('media_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('media_id');
            $table->string('type', 32);
            $table->string('disk', 32)->default('public');
            $table->string('path');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamps();

            $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete();
            $table->unique(['media_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_variants');
    }
};
