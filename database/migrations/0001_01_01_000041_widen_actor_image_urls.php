<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Avatar/copertina remoti (Threads / Instagram CDN e simili) usano URL
     * firmati con query string lunghe, oltre il VARCHAR(255) di default.
     */
    public function up(): void
    {
        Schema::table('actors', function (Blueprint $table) {
            $table->string('icon_url', 2048)->nullable()->change();
            $table->string('image_url', 2048)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('actors', function (Blueprint $table) {
            $table->string('icon_url', 255)->nullable()->change();
            $table->string('image_url', 255)->nullable()->change();
        });
    }
};
