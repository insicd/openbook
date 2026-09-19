<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->uuid('quoted_event_id')->nullable()->after('quoted_actor_id');
            $table->foreign('quoted_event_id')->references('id')->on('events')->nullOnDelete()->cascadeOnUpdate();
            $table->index('quoted_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropForeign(['quoted_event_id']);
            $table->dropIndex(['quoted_event_id']);
            $table->dropColumn('quoted_event_id');
        });
    }
};
