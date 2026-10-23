<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follows', function (Blueprint $table): void {
            $table->index(['follower_id', 'status', 'following_id'], 'follows_export_index');
        });
    }

    public function down(): void
    {
        Schema::table('follows', function (Blueprint $table): void {
            $table->dropIndex('follows_export_index');
        });
    }
};
