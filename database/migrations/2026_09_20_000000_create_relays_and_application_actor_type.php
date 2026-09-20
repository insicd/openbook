<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE actors MODIFY COLUMN type ENUM('person', 'group', 'feed', 'application') NOT NULL DEFAULT 'person'");
        }

        Schema::create('relays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('protocol', 32);
            $table->string('actor_uri', 2048)->nullable();
            $table->string('inbox_url', 2048);
            $table->char('inbox_url_hash', 64)->unique();
            $table->string('follow_activity_uri', 2048)->nullable();
            $table->string('state', 32)->default('idle');
            $table->boolean('receive_enabled')->default(true);
            $table->boolean('publish_enabled')->default(true);
            $table->text('last_error')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamps();

            $table->index(['state', 'receive_enabled']);
            $table->index(['state', 'publish_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relays');

        DB::table('actors')
            ->where('type', 'application')
            ->where('is_local', true)
            ->where('preferred_username', 'relay')
            ->delete();

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE actors MODIFY COLUMN type ENUM('person', 'group', 'feed') NOT NULL DEFAULT 'person'");
        }
    }
};
