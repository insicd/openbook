<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invalida la cache replies: la prima implementazione non seguiva la
     * paginazione Mastodon (`next`), quindi molti post remoti risultavano
     * "gia' recuperati" ma senza commenti. Al prossimo show verranno
     * ritentati.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('posts', 'replies_fetched_at')) {
            return;
        }

        DB::table('posts')->whereNotNull('uri')->update(['replies_fetched_at' => null]);
    }

    public function down(): void
    {
        // Irreversibile: la cache replies verra' semplicemente ripopolata.
    }
};
