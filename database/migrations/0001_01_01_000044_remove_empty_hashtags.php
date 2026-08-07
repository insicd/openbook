<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tag ActivityPub malformati (es. name "#" o vuoto) potevano creare righe
     * con name="" e rompere la generazione URL in sidebar e feed.
     */
    public function up(): void
    {
        DB::table('hashtags')->where('name', '')->delete();
    }

    public function down(): void
    {
        // Non recuperabile: i record erano invalidi.
    }
};
