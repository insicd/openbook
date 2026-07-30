<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Domini federati bloccati dall'istanza: niente fetch, consegna o
     * accettazione inbox da questi host.
     */
    public function up(): void
    {
        Schema::create('domain_blocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('domain', 255)->unique();
            $table->string('reason', 500)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_blocks');
    }
};
