<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Actor di tipo "feed": contatti RSS/Atom seguibili in locale (stile
     * Friendica), senza inbox/chiavi ActivityPub. I metadati del feed stanno
     * in "feed_sources".
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE actors MODIFY COLUMN type ENUM('person', 'group', 'feed') NOT NULL DEFAULT 'person'");
        }

        Schema::create('feed_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_id')->unique();
            $table->string('feed_url', 2048);
            $table->string('feed_url_hash', 64);
            $table->string('site_url', 2048)->nullable();
            $table->enum('format', ['atom', 'rss']);
            $table->string('etag')->nullable();
            $table->string('last_modified')->nullable();
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->foreign('actor_id')->references('id')->on('actors')->cascadeOnDelete();
            $table->unique('feed_url_hash');
            $table->index('last_fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_sources');

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE actors MODIFY COLUMN type ENUM('person', 'group') NOT NULL DEFAULT 'person'");
        }
    }
};
