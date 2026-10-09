<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('language', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Non troncare tag validi salvati dopo l'ampliamento.
        if (DB::table('posts')->whereRaw('LENGTH(language) > 8')->exists()) {
            throw new RuntimeException('Cannot narrow posts.language: language tags longer than 8 characters exist.');
        }

        Schema::table('posts', function (Blueprint $table) {
            $table->string('language', 8)->nullable()->change();
        });
    }
};
