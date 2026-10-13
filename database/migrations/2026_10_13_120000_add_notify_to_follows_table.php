<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Campanella Mastodon/Friendica sul Follow (`notify: true`): il
     * follower chiede una notifica per ogni nuovo post dell'Actor seguito,
     * in particolare i Group FEP-1b12.
     */
    public function up(): void
    {
        Schema::table('follows', function (Blueprint $table) {
            $table->boolean('notify')->default(false)->after('auto_announce_since');
            $table->index(['notify', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('follows', function (Blueprint $table) {
            $table->dropIndex(['notify', 'status']);
            $table->dropColumn('notify');
        });
    }
};
