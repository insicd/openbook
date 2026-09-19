<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_id')->nullable();
            $table->string('uri')->unique();
            $table->string('url', 2048)->nullable();
            $table->string('name', 500);
            $table->text('summary')->nullable();
            $table->longText('content')->nullable();
            $table->json('custom_emojis')->nullable();
            $table->string('language', 35)->nullable();
            $table->enum('visibility', ['public', 'unlisted', 'followers', 'direct']);
            $table->string('status', 32)->default('scheduled');
            $table->string('join_mode', 32)->nullable();
            $table->boolean('sensitive')->default(false);
            $table->boolean('is_online')->default(false);
            $table->string('external_participation_url', 2048)->nullable();
            $table->string('category', 100)->nullable();
            $table->timestamp('start_at');
            $table->timestamp('end_at')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->smallInteger('utc_offset_minutes')->nullable();
            $table->string('series_uri')->nullable();
            $table->unsignedInteger('participant_count')->nullable();
            $table->unsignedInteger('likes_count')->nullable();
            $table->timestamp('remote_counts_fetched_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('remote_updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->foreign('actor_id')->references('id')->on('actors')->nullOnDelete()->cascadeOnUpdate();
            $table->index(['actor_id', 'start_at']);
            $table->index(['visibility', 'status', 'start_at'], 'events_list_idx');
            $table->index(['visibility', 'status', 'end_at'], 'events_archive_idx');
        });

        Schema::create('event_locations', function (Blueprint $table) {
            $table->uuid('event_id')->primary();
            $table->unsignedBigInteger('geo_city_id')->nullable();
            $table->string('remote_uri', 2048)->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('name', 500)->nullable();
            $table->text('address')->nullable();
            $table->string('street_address', 500)->nullable();
            $table->string('locality', 200)->nullable();
            $table->string('region', 200)->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->string('country_code', 8)->nullable();
            $table->string('country_name', 200)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->enum('source', ['local', 'remote'])->default('remote');
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('geo_city_id')->references('geoname_id')->on('geo_cities')->nullOnDelete()->cascadeOnUpdate();
        });

        Schema::create('event_attributions', function (Blueprint $table) {
            $table->uuid('event_id');
            $table->uuid('actor_id');
            $table->unsignedSmallInteger('position')->default(0);

            $table->primary(['event_id', 'actor_id']);
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('actor_id')->references('id')->on('actors')->cascadeOnDelete()->cascadeOnUpdate();
            $table->unique(['event_id', 'position']);
            $table->index('actor_id');
        });

        Schema::create('event_recipients', function (Blueprint $table) {
            $table->uuid('event_id');
            $table->uuid('actor_id');

            $table->primary(['event_id', 'actor_id']);
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('actor_id')->references('id')->on('actors')->cascadeOnDelete()->cascadeOnUpdate();
            $table->index(['actor_id', 'event_id']);
        });

        Schema::create('event_hashtags', function (Blueprint $table) {
            $table->uuid('event_id');
            $table->uuid('hashtag_id');

            $table->primary(['event_id', 'hashtag_id']);
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('hashtag_id')->references('id')->on('hashtags')->cascadeOnDelete()->cascadeOnUpdate();
            $table->index('hashtag_id');
        });

        Schema::create('event_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('media_id');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete()->cascadeOnUpdate();
            $table->unique(['event_id', 'media_id']);
            $table->index(['event_id', 'position']);
        });

        Schema::create('event_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('url', 2048);
            $table->string('name', 500)->nullable();
            $table->string('media_type', 100)->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete()->cascadeOnUpdate();
            $table->index(['event_id', 'position']);
        });

        Schema::create('event_announces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('actor_id');
            $table->string('uri')->nullable()->unique();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('actor_id')->references('id')->on('actors')->cascadeOnDelete()->cascadeOnUpdate();
            $table->unique(['actor_id', 'event_id']);
            $table->index(['event_id', 'created_at']);
        });

        Schema::create('event_participations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('actor_id');
            $table->enum('status', ['pending', 'accepted', 'rejected']);
            $table->string('activity_uri')->nullable()->unique();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('actor_id')->references('id')->on('actors')->cascadeOnDelete()->cascadeOnUpdate();
            $table->unique(['event_id', 'actor_id']);
            $table->index(['actor_id', 'status', 'event_id'], 'event_participations_actor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_participations');
        Schema::dropIfExists('event_announces');
        Schema::dropIfExists('event_links');
        Schema::dropIfExists('event_attachments');
        Schema::dropIfExists('event_hashtags');
        Schema::dropIfExists('event_recipients');
        Schema::dropIfExists('event_attributions');
        Schema::dropIfExists('event_locations');
        Schema::dropIfExists('events');
    }
};
