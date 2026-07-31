<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Community locali (Actor ActivityPub di tipo Group): metadati di dominio
     * separati dall'Actor federato, come previsto dalla Fase 5 / FEP-1b12.
     */
    public function up(): void
    {
        Schema::create('communities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_id')->unique();
            $table->uuid('owner_user_id');
            $table->string('slug', 32)->unique();
            $table->boolean('is_private')->default(false);
            $table->unsignedInteger('members_count')->default(0);
            $table->unsignedInteger('posts_count')->default(0);
            $table->timestamps();

            $table->foreign('actor_id')->references('id')->on('actors')->cascadeOnDelete();
            $table->foreign('owner_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->index('owner_user_id');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->uuid('community_id')->nullable()->after('actor_id');
            $table->foreign('community_id')->references('id')->on('communities')->nullOnDelete();
            $table->index('community_id');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropForeign(['community_id']);
            $table->dropColumn('community_id');
        });

        Schema::dropIfExists('communities');
    }
};
