<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('notification_id')->unique();
            $table->timestamps();

            $table->foreign('notification_id')->references('id')->on('notifications')->cascadeOnDelete();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_notifications');
    }
};
