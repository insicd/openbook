<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('stats_content_delivery_buckets')->whereNotIn(
            'source_id', DB::table('stats_delivery_sources')->select('id')
        )->delete();

        Schema::table('stats_content_delivery_buckets', function (Blueprint $table): void {
            $table->index('source_id');
            $table->foreign('source_id')->references('id')->on('stats_delivery_sources')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stats_content_delivery_buckets', function (Blueprint $table): void {
            $table->dropForeign(['source_id']);
            $table->dropIndex(['source_id']);
        });
    }
};
