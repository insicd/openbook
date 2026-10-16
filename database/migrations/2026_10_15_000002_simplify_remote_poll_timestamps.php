<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remote_polls', function (Blueprint $table) {
            $table->renameColumn('observed_at', 'results_received_at');
            $table->dropColumn(['created_at', 'updated_at']);
        });
        Schema::table('remote_poll_options', function (Blueprint $table) {
            $table->dropColumn(['created_at', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('remote_poll_options', function (Blueprint $table) {
            $table->timestamps();
        });
        Schema::table('remote_polls', function (Blueprint $table) {
            $table->renameColumn('results_received_at', 'observed_at');
            // Historical insertion/update times were discarded and cannot be reconstructed.
            $table->timestamps();
        });
    }
};
