<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_items', function (Blueprint $table) {
            $table->uuid('relay_id')->nullable()->after('target_actor_id');
            $table->foreign('relay_id')->references('id')->on('relays')->nullOnDelete();
            $table->index(['relay_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('inbox_items', function (Blueprint $table) {
            $table->dropForeign(['relay_id']);
            $table->dropIndex(['relay_id', 'status']);
            $table->dropColumn('relay_id');
        });
    }
};
