<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cache delle collection ActivityPub followers/following di un Actor
     * remoto: conteggi autoritativi (totalItems), data di iscrizione
     * (published del documento Person) e un campione della lista, senza
     * mescolarla al grafo locale "follows".
     */
    public function up(): void
    {
        Schema::table('actors', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('posts_fetched_at');
            $table->unsignedInteger('followers_count')->nullable()->after('published_at');
            $table->unsignedInteger('following_count')->nullable()->after('followers_count');
            $table->timestamp('collections_fetched_at')->nullable()->after('following_count');
        });

        Schema::create('remote_collection_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_id');
            $table->string('collection', 16);
            $table->string('member_uri');
            $table->uuid('member_actor_id')->nullable();
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->foreign('actor_id')->references('id')->on('actors')->cascadeOnDelete();
            $table->foreign('member_actor_id')->references('id')->on('actors')->nullOnDelete();
            $table->unique(['actor_id', 'collection', 'member_uri']);
            $table->index(['actor_id', 'collection', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_collection_members');

        Schema::table('actors', function (Blueprint $table) {
            $table->dropColumn([
                'published_at',
                'followers_count',
                'following_count',
                'collections_fetched_at',
            ]);
        });
    }
};
