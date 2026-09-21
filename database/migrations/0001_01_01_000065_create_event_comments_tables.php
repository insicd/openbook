<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('parent_event_comment_id')->nullable();
            $table->uuid('actor_id');
            $table->string('uri')->nullable()->unique();
            $table->text('body');
            $table->json('custom_emojis')->nullable();
            $table->enum('status', ['published', 'deleted'])->default('published');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('parent_event_comment_id')->references('id')->on('event_comments')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('actor_id')->references('id')->on('actors')->cascadeOnDelete()->cascadeOnUpdate();
            $table->index(['event_id', 'created_at']);
            $table->index('parent_event_comment_id');
        });

        Schema::create('event_comment_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_comment_id');
            $table->uuid('media_id');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->foreign('event_comment_id')->references('id')->on('event_comments')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete()->cascadeOnUpdate();
            $table->unique(['event_comment_id', 'media_id']);
            $table->index(['event_comment_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_comment_attachments');
        Schema::dropIfExists('event_comments');
    }
};
