<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invalida la cache replies dopo l'introduzione dei signed fetch:
     * i tentativi precedenti (GET anonimi) possono aver stampato
     * replies_fetched_at su 401 senza aver recuperato commenti.
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
