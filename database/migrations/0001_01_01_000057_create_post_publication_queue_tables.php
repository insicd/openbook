<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_publication_queue', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_id');
            $table->json('payload');
            $table->enum('status', ['pending', 'processing', 'failed', 'published'])->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('claim_token')->nullable()->unique();
            $table->timestamp('claimed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->uuid('post_id')->nullable()->unique();
            $table->timestamps();

            $table->foreign('actor_id')->references('id')->on('actors')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('post_id')->references('id')->on('posts')->nullOnDelete()->cascadeOnUpdate();
            $table->index(['status', 'created_at'], 'post_publication_queue_pending_index');
            $table->index(['status', 'claimed_at'], 'post_publication_queue_claim_index');
        });

        Schema::create('post_publication_queue_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('publication_id');
            $table->unsignedInteger('position');
            $table->string('disk', 32)->default('local');
            $table->string('path', 1024);
            $table->string('original_name')->nullable();
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('byte_size');
            $table->enum('media_type', ['image', 'audio', 'video']);
            $table->enum('processing', ['copy', 'transcode'])->default('copy');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->decimal('frame_rate', 8, 3)->nullable();
            $table->string('container', 128)->nullable();
            $table->string('video_codec', 64)->nullable();
            $table->string('audio_codec', 64)->nullable();
            $table->string('pixel_format', 64)->nullable();
            $table->text('alt_text')->nullable();
            $table->timestamps();

            $table->foreign('publication_id')->references('id')->on('post_publication_queue')->cascadeOnDelete()->cascadeOnUpdate();
            $table->unique(['publication_id', 'position'], 'post_publication_queue_attachment_position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_publication_queue_attachments');
        Schema::dropIfExists('post_publication_queue');
    }
};
