<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('stats_post_delivery_buckets', 'stats_content_delivery_buckets');
        DB::table('stats_collections')->where('metric', 'post_delivery')->update(['metric' => 'content_delivery']);
    }

    public function down(): void
    {
        Schema::rename('stats_content_delivery_buckets', 'stats_post_delivery_buckets');
        DB::table('stats_collections')->where('metric', 'content_delivery')->update(['metric' => 'post_delivery']);
    }
};
