<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contatore monotono per il polling live delle notifiche: il client
     * invia If-None-Match e, se invariato, il server risponde 304 con una
     * sola lettura di questa colonna (niente count/elenco).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('notifications_revision')->default(0)->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notifications_revision');
        });
    }
};
