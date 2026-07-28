<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Archivio chiave/valore per la configurazione dell'istanza gestita
     * dall'installer e dal pannello di amministrazione (nome dell'istanza,
     * stato di installazione, token cron, ecc.). Non contiene segreti in
     * chiaro: i valori sensibili vengono cifrati dal modello SystemSetting.
     */
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
