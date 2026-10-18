<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable()->default(null)->change();
        });

        // These values were assigned by the server before a personal choice existed.
        DB::table('user_settings')->update(['timezone' => null]);
    }

    public function down(): void
    {
        DB::table('user_settings')->whereNull('timezone')->update(['timezone' => config('app.timezone', 'UTC')]);

        Schema::table('user_settings', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable(false)->default('UTC')->change();
        });
    }
};
