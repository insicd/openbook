<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_locations', function (Blueprint $table) {
            $table->uuid('post_id')->primary();
            $table->unsignedBigInteger('geo_city_id')->nullable();
            $table->string('name', 200);
            $table->string('admin1_name', 200)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('country_name', 200)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->enum('source', ['local', 'remote'])->default('local');
            $table->timestamps();

            $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('geo_city_id')->references('geoname_id')->on('geo_cities')->nullOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_locations');
    }
};
