<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dopo la correzione di to/cc come stringa (GoToSocial) e della
     * dereferenziazione delle collection replies, ritenta i fetch.
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
        // Irreversibile.
    }
};
