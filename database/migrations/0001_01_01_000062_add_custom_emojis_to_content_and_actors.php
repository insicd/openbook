<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->json('custom_emojis')->nullable();
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->json('custom_emojis')->nullable();
        });

        Schema::table('actors', function (Blueprint $table) {
            $table->json('custom_emojis')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('actors', function (Blueprint $table) {
            $table->dropColumn('custom_emojis');
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn('custom_emojis');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('custom_emojis');
        });
    }
};
