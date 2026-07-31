<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * URL remoto di un allegato federato (Pixelfed, WordPress, ecc.): non
     * viene scaricato sull'istanza; "path" resta un segnaposto locale.
     */
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->string('remote_url', 2048)->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn('remote_url');
        });
    }
};
