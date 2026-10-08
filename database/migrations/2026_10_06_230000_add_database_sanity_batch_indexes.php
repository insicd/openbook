<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['likes' => 'likeable', 'mentions' => 'mentionable', 'notifications' => 'notifiable'] as $name => $morph) {
            Schema::table($name, function (Blueprint $table) use ($name, $morph): void {
                $table->index([$morph.'_type', 'id', $morph.'_id'], $name.'_sanity_batch_index');
            });
        }
    }

    public function down(): void
    {
        foreach (['likes', 'mentions', 'notifications'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropIndex($name.'_sanity_batch_index');
            });
        }
    }
};
