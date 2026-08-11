<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WordPress ActivityPub (e altri blog) usano spesso il dominio intero come
     * preferredUsername (es. "blog.example.com"), oltre il limite locale di 32
     * caratteri riservato agli account registrati su questa istanza.
     */
    public function up(): void
    {
        Schema::table('actors', function (Blueprint $table) {
            $table->string('preferred_username', 255)->change();
        });
    }

    public function down(): void
    {
        Schema::table('actors', function (Blueprint $table) {
            $table->string('preferred_username', 32)->change();
        });
    }
};
