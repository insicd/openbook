<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remote_polls', function (Blueprint $table) {
            $table->dateTime('remote_updated_at')->nullable();
            $table->dateTime('observed_at')->nullable();
            $table->uuid('source_item_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('remote_polls', function (Blueprint $table) {
            $table->dropColumn(['remote_updated_at', 'observed_at', 'source_item_id']);
        });
    }
};
