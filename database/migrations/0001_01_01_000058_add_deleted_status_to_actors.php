<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gli Actor remoti cancellati restano come tombstone interne per
     * preservare i thread, ma liberano URI e handle federati originali.
     */
    public function up(): void
    {
        Schema::table('actors', function (Blueprint $table) {
            $table->enum('status', ['active', 'suspended', 'blocked', 'deleted'])->default('active')->change();
            $table->timestamp('deleted_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        DB::table('actors')->where('status', 'deleted')->update(['status' => 'blocked']);

        Schema::table('actors', function (Blueprint $table) {
            $table->dropColumn('deleted_at');
            $table->enum('status', ['active', 'suspended', 'blocked'])->default('active')->change();
        });
    }
};
