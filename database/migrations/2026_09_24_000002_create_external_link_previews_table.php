<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_link_previews', function (Blueprint $table): void {
            $table->char('url_hash', 64)->primary();
            $table->text('url');
            $table->boolean('available');
            $table->string('title', 300)->nullable();
            $table->text('description')->nullable();
            $table->string('site_name', 200)->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('fetched_at');
            $table->index('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_link_previews');
    }
};
