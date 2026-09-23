<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Condivisione diretta automatica (stile Friendica) dei nuovi post
     * pubblici di un contatto seguito: Person/Group/feed, locale o remoto.
     */
    public function up(): void
    {
        Schema::table('follows', function (Blueprint $table) {
            $table->boolean('auto_announce')->default(false)->after('remote_activity_uri');
            $table->timestamp('auto_announce_since')->nullable()->after('auto_announce');
            $table->index(['auto_announce', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('follows', function (Blueprint $table) {
            $table->dropIndex(['auto_announce', 'status']);
            $table->dropColumn(['auto_announce', 'auto_announce_since']);
        });
    }
};
