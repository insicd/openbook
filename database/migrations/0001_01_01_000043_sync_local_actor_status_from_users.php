<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Allinea actors.status allo stato moderazione su users (fix dati creati
 * prima della sincronizzazione automatica in StaffUserManager).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('actors')
            ->where('is_local', true)
            ->whereIn('user_id', function ($query) {
                $query->select('id')->from('users')->where('status', 'suspended');
            })
            ->update(['status' => 'suspended']);

        DB::table('actors')
            ->where('is_local', true)
            ->whereIn('user_id', function ($query) {
                $query->select('id')->from('users')->where('status', 'disabled');
            })
            ->update(['status' => 'blocked']);
    }

    public function down(): void
    {
        // Non reversibile in modo sicuro: lo status Actor non e' storicizzato.
    }
};
