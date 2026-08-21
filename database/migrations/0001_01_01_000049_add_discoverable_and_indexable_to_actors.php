<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flag Mastodon / FEP-5feb sull'Actor: discoverable (directory e
     * suggerimenti) e indexable (consenso all'indicizzazione dei post
     * pubblici). In locale restano anche su user_settings, allineati
     * come manually_approves_followers.
     */
    public function up(): void
    {
        Schema::table('actors', function (Blueprint $table) {
            $table->boolean('discoverable')->default(true)->after('manually_approves_followers');
            $table->boolean('indexable')->default(false)->after('discoverable');
        });

        Schema::table('user_settings', function (Blueprint $table) {
            $table->boolean('indexable')->default(false)->after('discoverable');
        });

        $hiddenUserIds = DB::table('user_settings')
            ->where('discoverable', false)
            ->pluck('user_id');

        if ($hiddenUserIds->isNotEmpty()) {
            DB::table('actors')
                ->whereIn('user_id', $hiddenUserIds)
                ->update(['discoverable' => false]);
        }
    }

    public function down(): void
    {
        Schema::table('actors', function (Blueprint $table) {
            $table->dropColumn(['discoverable', 'indexable']);
        });

        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn('indexable');
        });
    }
};
