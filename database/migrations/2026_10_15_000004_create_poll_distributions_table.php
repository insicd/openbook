<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poll_distributions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('poll_id')->unique()->constrained('polls')->cascadeOnDelete();
            $table->unsignedBigInteger('revision');
            $table->json('activity');
            $table->string('phase', 16)->default('actors');
            $table->text('cursor')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('poll_distributions') && DB::table('poll_distributions')->exists()) {
            throw new LogicException('Complete active poll distributions before rolling back their snapshots.');
        }
        Schema::dropIfExists('poll_distributions');
    }
};
