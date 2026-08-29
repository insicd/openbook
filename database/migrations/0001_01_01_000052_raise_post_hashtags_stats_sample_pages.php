<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * InnoDB stima la cardinalita' di "post_hashtags" campionando di default
     * 20 pagine (innodb_stats_persistent_sample_pages). Su tabelle che
     * crescono, quel campione e' spesso troppo piccolo: l'optimizer di MySQL
     * puo' partire da un full scan di "hashtags" invece che dai post recenti
     * (indice posts_visibility_status_published_at_index) nella query delle
     * tendenze. Un campione da 100 pagine rende le stime piu' stabili; gli
     * ANALYZE successivi (innodb_stats_auto_recalc) lo riusano.
     *
     * SQLite (suite di test) non ha questa opzione: la migration e' un no-op.
     */
    public function up(): void
    {
        if (! $this->supportsInnoDbPersistentStats()) {
            return;
        }

        DB::statement('ALTER TABLE post_hashtags STATS_SAMPLE_PAGES = 100');
        // ANALYZE restituisce un result set (Table/Op/Msg): va letto, non
        // eseguito come statement vuoto (PDO "unbuffered queries").
        DB::select('ANALYZE TABLE post_hashtags');
    }

    public function down(): void
    {
        if (! $this->supportsInnoDbPersistentStats()) {
            return;
        }

        // 20 e' il default di innodb_stats_persistent_sample_pages.
        DB::statement('ALTER TABLE post_hashtags STATS_SAMPLE_PAGES = 20');
        DB::select('ANALYZE TABLE post_hashtags');
    }

    private function supportsInnoDbPersistentStats(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
