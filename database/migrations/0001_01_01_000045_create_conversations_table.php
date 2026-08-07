<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chat 1:1 federata: ogni conversazione lega due Actor; i messaggi restano
     * post "direct" collegati via conversation_id.
     */
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('participant_low_id');
            $table->uuid('participant_high_id');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['participant_low_id', 'participant_high_id']);
            $table->foreign('participant_low_id')->references('id')->on('actors')->cascadeOnDelete();
            $table->foreign('participant_high_id')->references('id')->on('actors')->cascadeOnDelete();
            $table->index('last_message_at');
        });

        Schema::create('conversation_reads', function (Blueprint $table) {
            $table->uuid('conversation_id');
            $table->uuid('user_id');
            $table->timestamp('last_read_at')->nullable();

            $table->primary(['conversation_id', 'user_id']);
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->uuid('conversation_id')->nullable()->after('community_id');
            $table->foreign('conversation_id')->references('id')->on('conversations')->nullOnDelete();
            $table->index('conversation_id');
        });

        Schema::table('user_settings', function (Blueprint $table) {
            $table->string('direct_message_policy', 20)->default('everyone')->after('discoverable');
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn('direct_message_policy');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dropForeign(['conversation_id']);
            $table->dropColumn('conversation_id');
        });

        Schema::dropIfExists('conversation_reads');
        Schema::dropIfExists('conversations');
    }
};
