<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remote_polls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('post_id')->unique();
            $table->boolean('multiple');
            $table->dateTime('ends_at')->nullable();
            $table->boolean('closed')->default(false);
            $table->dateTime('closed_at')->nullable();
            $table->unsignedBigInteger('voters_count')->nullable();
            $table->timestamps();
            $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
        });

        Schema::create('remote_poll_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('poll_id');
            $table->unsignedSmallInteger('position');
            $table->text('name');
            // Exact UTF-8 identity, independent of the database's text collation.
            $table->char('name_hash', 64);
            $table->unsignedBigInteger('votes_count')->nullable();
            $table->timestamps();
            $table->foreign('poll_id')->references('id')->on('remote_polls')->cascadeOnDelete();
            $table->unique(['poll_id', 'position'], 'remote_poll_options_position_unique');
            $table->unique(['poll_id', 'name_hash'], 'remote_poll_options_name_unique');
        });

        Schema::create('remote_poll_votes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('poll_id');
            $table->uuid('actor_id');
            $table->timestamps();
            $table->foreign('poll_id')->references('id')->on('remote_polls')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('actors')->cascadeOnDelete();
            $table->unique(['poll_id', 'actor_id'], 'remote_poll_votes_actor_unique');
            $table->index('actor_id');
        });

        Schema::create('remote_poll_vote_choices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vote_id');
            $table->unsignedSmallInteger('position');
            // Snapshot: deliberately no foreign key to the mutable remote option.
            $table->text('name');
            $table->char('name_hash', 64);
            $table->timestamps();
            $table->foreign('vote_id')->references('id')->on('remote_poll_votes')->cascadeOnDelete();
            $table->unique(['vote_id', 'name_hash'], 'remote_poll_vote_choices_name_unique');
            $table->unique(['vote_id', 'position'], 'remote_poll_vote_choices_position_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_poll_vote_choices');
        Schema::dropIfExists('remote_poll_votes');
        Schema::dropIfExists('remote_poll_options');
        Schema::dropIfExists('remote_polls');
    }
};
