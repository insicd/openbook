<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabella unificata degli Actor ActivityPub: copre sia gli attori locali
     * (Person per gli utenti, in futuro Group per le community) sia la cache
     * degli attori remoti scoperti tramite federazione. "is_local" distingue
     * chiaramente le due categorie come richiesto dall'architettura.
     *
     * Le community (Group) e i relativi riferimenti verranno aggiunti in una
     * fase successiva tramite colonna nullable "community_id" + migration
     * dedicata, per non introdurre nella Fase 1 una tabella "communities"
     * non ancora utilizzata.
     */
    public function up(): void
    {
        Schema::create('actors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable()->unique();
            $table->enum('type', ['person', 'group'])->default('person');
            $table->boolean('is_local')->default(true);
            $table->string('preferred_username', 32);
            $table->string('domain');
            $table->string('uri')->unique();
            $table->string('name')->nullable();
            $table->text('summary')->nullable();
            $table->string('icon_url')->nullable();
            $table->string('image_url')->nullable();
            $table->boolean('manually_approves_followers')->default(false);
            $table->enum('status', ['active', 'suspended', 'blocked'])->default('active');
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['preferred_username', 'domain']);
            $table->index('is_local');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actors');
    }
};
