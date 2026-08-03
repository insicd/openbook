<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comment_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('comment_id');
            $table->uuid('media_id');
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();

            $table->foreign('comment_id')->references('id')->on('comments')->cascadeOnDelete();
            $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete();
            $table->unique(['comment_id', 'media_id']);
            $table->index(['comment_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_attachments');
    }
};
