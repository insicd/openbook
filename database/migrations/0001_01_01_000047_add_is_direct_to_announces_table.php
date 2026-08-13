<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Distingue la condivisione diretta (repost nel feed) dalla sola citazione
     * con commento: entrambe creano un Announce, ma solo la prima espone
     * "Annulla condivisione" nel menu.
     */
    public function up(): void
    {
        Schema::table('announces', function (Blueprint $table) {
            $table->boolean('is_direct')->default(true)->after('post_id');
        });

        DB::table('announces as a')
            ->whereExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('posts as p')
                    ->whereColumn('p.actor_id', 'a.actor_id')
                    ->whereColumn('p.quoted_post_id', 'a.post_id')
                    ->where('p.status', 'published');
            })
            ->update(['is_direct' => false]);
    }

    public function down(): void
    {
        Schema::table('announces', function (Blueprint $table) {
            $table->dropColumn('is_direct');
        });
    }
};
