<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropIndex(['status', 'created_at']);
            $table->index(['status', 'created_at', 'id'], 'reports_status_created_id_index');
            $table->index(['created_at', 'id'], 'reports_created_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropIndex('reports_created_id_index');
            $table->dropIndex('reports_status_created_id_index');
            $table->index(['status', 'created_at']);
        });
    }
};
