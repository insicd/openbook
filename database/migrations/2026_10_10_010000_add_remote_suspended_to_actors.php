<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actors', function (Blueprint $table): void {
            $table->boolean('remote_suspended')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('actors', function (Blueprint $table): void {
            $table->dropColumn('remote_suspended');
        });
    }
};
