<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['posts', 'inbox_items'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->json('received_via')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['inbox_items', 'posts'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('received_via'));
        }
    }
};
