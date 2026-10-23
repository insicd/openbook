<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropForeign(['reporter_id']);
        });
        Schema::table('reports', function (Blueprint $table): void {
            $table->uuid('reporter_id')->nullable()->change();
            $table->foreign('reporter_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('origin', 16)->default('local');
            $table->foreignUuid('reporter_actor_id')->nullable()->constrained('actors')->nullOnDelete();
            $table->text('reporter_uri')->nullable();
            $table->string('reporter_domain')->nullable();
            $table->text('remote_activity_uri')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('closure_reason', 40)->nullable();
        });

        Schema::create('report_targets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_id')->constrained('reports')->cascadeOnDelete();
            $table->string('type', 20);
            $table->text('uri');
            $table->char('uri_hash', 64);
            $table->boolean('unavailable');
            $table->foreignUuid('actor_id')->nullable()->constrained('actors')->nullOnDelete();
            $table->foreignUuid('post_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->foreignUuid('comment_id')->nullable()->constrained('comments')->nullOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->foreignUuid('event_comment_id')->nullable()->constrained('event_comments')->nullOnDelete();
            $table->unique(['report_id', 'uri_hash']);
        });

        // A receipt survives deletion of both the inbox item and the case.
        Schema::create('report_receipts', function (Blueprint $table): void {
            $table->char('activity_hash', 64)->primary();
            $table->foreignUuid('report_id')->nullable()->constrained('reports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_receipts');
        Schema::dropIfExists('report_targets');

        // The old schema cannot represent a report without a local User.
        DB::table('reports')->whereNull('reporter_id')->delete();
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropForeign(['reporter_actor_id']);
            $table->dropForeign(['reporter_id']);
        });
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropColumn(['origin', 'reporter_actor_id', 'reporter_uri', 'reporter_domain',
                'remote_activity_uri', 'closed_at', 'closure_reason']);
            $table->uuid('reporter_id')->nullable(false)->change();
            $table->foreign('reporter_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
