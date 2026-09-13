<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_cities', function (Blueprint $table) {
            $table->unsignedBigInteger('geoname_id')->primary();
            $table->string('name', 200);
            $table->string('ascii_name', 200);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->smallInteger('latitude_bucket');
            $table->smallInteger('longitude_bucket');
            $table->char('country_code', 2);
            $table->string('country_name', 200)->nullable();
            $table->string('admin1_code', 20)->nullable();
            $table->string('admin1_name', 200)->nullable();
            $table->string('feature_code', 10);
            $table->unsignedBigInteger('population')->default(0);
            $table->uuid('catalog_batch');
            $table->timestamps();

            $table->index('name');
            $table->index('ascii_name');
            $table->index('admin1_name');
            $table->index('country_name');
            $table->index(['latitude_bucket', 'longitude_bucket'], 'geo_cities_coordinate_bucket_index');
            $table->index('catalog_batch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_cities');
    }
};
