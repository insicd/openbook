<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stats_collections', function (Blueprint $table): void {
            $table->string('metric', 32)->primary();
            $table->dateTime('started_at');
        });
        Schema::create('stats_delivery_sources', function (Blueprint $table): void {
            $table->char('id', 64)->primary();
            $table->string('kind', 16);
            $table->string('label')->nullable();
        });
        Schema::create('stats_post_delivery_buckets', function (Blueprint $table): void {
            $table->date('day');
            $table->char('source_id', 64);
            $table->unsignedBigInteger('count')->default(0);
            $table->primary(['day', 'source_id']);
        });
        DB::table('stats_collections')->insert(['metric' => 'post_delivery', 'started_at' => now('UTC')->format('Y-m-d H:i:s')]);
    }

    public function down(): void
    {
        Schema::dropIfExists('stats_post_delivery_buckets');
        Schema::dropIfExists('stats_delivery_sources');
        Schema::dropIfExists('stats_collections');
    }
};
