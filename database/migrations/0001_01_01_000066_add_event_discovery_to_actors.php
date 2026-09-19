<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actor_endpoints', function (Blueprint $table) {
            $table->string('events')->nullable()->after('outbox');
        });

        Schema::table('actors', function (Blueprint $table) {
            $table->timestamp('events_fetched_at')->nullable()->after('posts_fetched_at');
        });
    }

    public function down(): void
    {
        Schema::table('actors', function (Blueprint $table) {
            $table->dropColumn('events_fetched_at');
        });

        Schema::table('actor_endpoints', function (Blueprint $table) {
            $table->dropColumn('events');
        });
    }
};
