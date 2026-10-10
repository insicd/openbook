<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actors', function (Blueprint $table): void {
            $table->json('also_known_as')->nullable();
            $table->foreignUuid('moved_to_actor_id')->nullable()->constrained('actors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('actors', function (Blueprint $table): void {
            $table->dropForeign(['moved_to_actor_id']);
            $table->dropColumn(['also_known_as', 'moved_to_actor_id']);
        });
    }
};
