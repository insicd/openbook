<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->string('avatar_disk', 32)->default('public')->after('bio');
            $table->string('cover_disk', 32)->default('public')->after('avatar_path');
        });
        Schema::table('actors', function (Blueprint $table): void {
            $table->string('icon_disk', 32)->nullable();
            $table->string('icon_path')->nullable();
            $table->string('image_disk', 32)->nullable();
            $table->string('image_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('actors', fn (Blueprint $table) => $table->dropColumn(['icon_disk', 'icon_path', 'image_disk', 'image_path']));
        Schema::table('profiles', fn (Blueprint $table) => $table->dropColumn(['avatar_disk', 'cover_disk']));
    }
};
