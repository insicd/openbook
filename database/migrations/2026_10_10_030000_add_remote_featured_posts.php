<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actor_endpoints', function (Blueprint $table): void {
            $table->text('featured')->nullable();
        });
        Schema::table('actors', function (Blueprint $table): void {
            $table->json('featured_post_ids')->nullable();
            $table->timestamp('featured_fetched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('actors', function (Blueprint $table): void {
            $table->dropColumn(['featured_post_ids', 'featured_fetched_at']);
        });
        Schema::table('actor_endpoints', function (Blueprint $table): void {
            $table->dropColumn('featured');
        });
    }
};
